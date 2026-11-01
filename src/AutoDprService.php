<?php

namespace TacraftSociality\AutoDpr;

use Carbon\Carbon;
use Flarum\Discussion\Discussion;
use Flarum\Post\Post;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Eloquent\Builder;
use RuntimeException;
use Throwable;

/**
 * 永久删除“被用户自己删除（隐藏）”的讨论与回复。
 *
 * 删除是幂等的：即使两个进程同时执行，重复处理同一条内容也不会出错。
 */
class AutoDprService
{
    /** 运行锁的有效时长（分钟），防止并发执行。 */
    private const LOCK_MINUTES = 15;

    private ?bool $columnsAvailable = null;

    public function __construct(
        private readonly Config $config,
        private readonly ConnectionInterface $db,
    ) {
    }

    public function isEnabled(): bool
    {
        return $this->config->enabled();
    }

    public function lastRunAt(): ?Carbon
    {
        return $this->parseDate($this->config->raw('last_run_at'));
    }

    /**
     * 按“上次运行时间 + 间隔”计算下次应当运行的时间。
     */
    public function nextRunAt(): ?Carbon
    {
        if (! $this->config->enabled()) {
            return null;
        }

        $last = $this->lastRunAt();

        return $last === null ? Carbon::now() : $last->copy()->addMinutes($this->config->intervalMinutes());
    }

    public function isDue(?Carbon $now = null): bool
    {
        if (! $this->config->enabled()) {
            return false;
        }

        $now = $now ?? Carbon::now();
        $next = $this->nextRunAt();

        return $next === null || $next->lessThanOrEqualTo($now);
    }

    /**
     * 执行一次清理。
     *
     * @param  bool    $force   忽略开关与间隔（用于“立即执行”按钮与控制台 --force）。
     * @param  string  $source  manual / request / scheduler / cli
     *
     * @return array{discussions:int,posts:int,error:?string,status:string,source:string}
     */
    public function run(bool $force = false, string $source = 'manual'): array
    {
        $now = Carbon::now();

        if (! $force) {
            if (! $this->config->enabled()) {
                return $this->report($source, 'disabled');
            }

            if (! $this->isDue($now)) {
                return $this->report($source, 'not_due');
            }

            if (! $this->claimLock($now)) {
                return $this->report($source, 'busy');
            }
        } else {
            $this->writeLock($now);
        }

        $result = ['discussions' => 0, 'posts' => 0, 'error' => null, 'limit' => 0, 'retention_minutes' => 0];

        try {
            $result = array_merge($result, $this->purge());
        } catch (Throwable $e) {
            Log::error('[auto-dpr] purge failed: '.$e->getMessage(), ['exception' => $e]);
            $result['error'] = $e->getMessage();
        }

        $this->releaseLock();

        $this->config->set('last_run_at', $now->toIso8601String());
        $this->config->set('last_run_source', $source);
        $this->config->set('last_deleted_discussions', (string) ((int) ($result['discussions'] ?? 0)));
        $this->config->set('last_deleted_posts', (string) ((int) ($result['posts'] ?? 0)));

        return array_merge($result, [
            'status' => $result['error'] === null ? 'ran' : 'error',
            'source' => $source,
            'run_at' => $now->toIso8601String(),
            'enabled' => $this->config->enabled(),
        ]);
    }

    /**
     * 后台“概览”使用的状态数据。
     */
    public function status(): array
    {
        $pending = $this->pendingCounts();
        $last = $this->lastRunAt();
        $next = $this->nextRunAt();

        return [
            'enabled'                => $this->config->enabled(),
            'interval_minutes'       => $this->config->intervalMinutes(),
            'retention_minutes'      => $this->config->retentionMinutes(),
            'batch_size'             => $this->config->batchSize(),
            'delete_discussions'     => $this->config->deleteDiscussions(),
            'delete_posts'           => $this->config->deletePosts(),
            'only_author_hidden'     => $this->config->onlyAuthorHidden(),
            'run_on_request'         => $this->config->runOnRequest(),
            'last_run_at'            => $last?->toIso8601String(),
            'last_run_source'        => $this->config->raw('last_run_source'),
            'last_deleted_discussions' => $this->config->int('last_deleted_discussions'),
            'last_deleted_posts'     => $this->config->int('last_deleted_posts'),
            'next_run_at'            => $next?->toIso8601String(),
            'due'                    => $this->isDue(),
            'pending_discussions'    => $pending['discussions'],
            'pending_posts'          => $pending['posts'],
            'columns_available'      => $pending['available'],
        ];
    }

    /**
     * 统计当前符合条件、等待永久删除的数量。
     *
     * @return array{discussions:int,posts:int,available:bool}
     */
    public function pendingCounts(): array
    {
        if (! $this->columnsAvailable()) {
            return ['discussions' => 0, 'posts' => 0, 'available' => false];
        }

        $cutoff = Carbon::now()->subMinutes($this->config->retentionMinutes());
        $discussions = 0;
        $posts = 0;

        try {
            if ($this->config->deleteDiscussions()) {
                $discussions = (int) $this->discussionQuery($cutoff)->count();
            }

            if ($this->config->deletePosts()) {
                $posts = (int) $this->postQuery($cutoff)->count();
            }
        } catch (Throwable $e) {
            Log::error('[auto-dpr] failed to count pending content: '.$e->getMessage());
        }

        return ['discussions' => $discussions, 'posts' => $posts, 'available' => true];
    }

    /**
     * @return array{discussions:int,posts:int,limit:int,retention_minutes:int,cutoff:string}
     */
    private function purge(): array
    {
        $this->assertColumns();

        $limit = $this->config->batchSize();
        $cutoff = Carbon::now()->subMinutes($this->config->retentionMinutes());
        $discussions = 0;
        $posts = 0;

        if ($this->config->deleteDiscussions()) {
            $ids = $this->discussionQuery($cutoff)
                ->orderBy('id')
                ->limit($limit)
                ->pluck('id')
                ->all();

            foreach ($ids as $id) {
                if ($this->purgeDiscussion((int) $id)) {
                    $discussions++;
                }
            }
        }

        $remaining = max(0, $limit - $discussions);

        if ($remaining > 0 && $this->config->deletePosts()) {
            $ids = $this->postQuery($cutoff)
                ->orderBy('id')
                ->limit($remaining)
                ->pluck('id')
                ->all();

            foreach ($ids as $id) {
                if ($this->purgePost((int) $id)) {
                    $posts++;
                }
            }
        }

        return [
            'discussions'       => $discussions,
            'posts'             => $posts,
            'limit'             => $limit,
            'retention_minutes' => $this->config->retentionMinutes(),
            'cutoff'            => $cutoff->toIso8601String(),
        ];
    }

    /**
     * 被用户删除（隐藏）的讨论。
     */
    private function discussionQuery(Carbon $cutoff): Builder
    {
        $query = Discussion::query()
            ->whereNotNull('hidden_at')
            ->where('hidden_at', '<=', $cutoff);

        if ($this->config->onlyAuthorHidden() && $this->hasColumn('discussions', 'hidden_user_id')) {
            $query->whereColumn('hidden_user_id', 'user_id');
        }

        return $query;
    }

    /**
     * 被用户删除（隐藏）的回复。
     *
     * - 只处理 type = comment 的回复；
     * - 跳过主题首帖（number = 1），首帖随主题一起处理；
     * - 跳过“所在主题本身已被隐藏”的回复，它们会随主题一起删除。
     */
    private function postQuery(Carbon $cutoff): Builder
    {
        $query = Post::query()
            ->whereNotNull('hidden_at')
            ->where('hidden_at', '<=', $cutoff)
            ->where('type', 'comment')
            ->where('number', '>', 1)
            ->whereNotIn('discussion_id', function ($sub) {
                $sub->select('id')->from('discussions')->whereNotNull('hidden_at');
            });

        if ($this->config->onlyAuthorHidden() && $this->hasColumn('posts', 'hidden_user_id')) {
            $query->whereColumn('hidden_user_id', 'user_id');
        }

        return $query;
    }

    private function purgeDiscussion(int $id): bool
    {
        // 先物理删除主题下的所有回复（包括首帖），避免外键约束阻止删除主题。
        try {
            $this->db->table('posts')->where('discussion_id', $id)->delete();
        } catch (Throwable $e) {
            Log::warning('[auto-dpr] failed to delete posts of discussion '.$id.': '.$e->getMessage());
        }

        return $this->purgeModel(Discussion::class, $id);
    }

    private function purgePost(int $id): bool
    {
        return $this->purgeModel(Post::class, $id);
    }

    /**
     * 三级兜底删除：forceDelete() → delete() → 原生 SQL。
     */
    private function purgeModel(string $modelClass, int $id): bool
    {
        try {
            $model = $modelClass::query()->withoutGlobalScopes()->whereKey($id)->first();

            if ($model !== null) {
                if (method_exists($model, 'forceDelete')) {
                    $model->forceDelete();
                } else {
                    $model->delete();
                }
            }
        } catch (Throwable $e) {
            Log::warning('[auto-dpr] forceDelete failed for '.$modelClass.'#'.$id.': '.$e->getMessage());
        }

        if ($this->exists($modelClass, $id)) {
            try {
                /** @var \Illuminate\Database\Eloquent\Model $instance */
                $instance = new $modelClass();
                $this->db->table($instance->getTable())->where('id', $id)->delete();
            } catch (Throwable $e) {
                Log::warning('[auto-dpr] raw delete failed for '.$modelClass.'#'.$id.': '.$e->getMessage());
            }
        }

        return ! $this->exists($modelClass, $id);
    }

    private function exists(string $modelClass, int $id): bool
    {
        try {
            return $modelClass::query()->withoutGlobalScopes()->whereKey($id)->exists();
        } catch (Throwable) {
            return false;
        }
    }

    public function columnsAvailable(): bool
    {
        if ($this->columnsAvailable !== null) {
            return $this->columnsAvailable;
        }

        $this->columnsAvailable = $this->hasColumn('discussions', 'hidden_at')
            && $this->hasColumn('posts', 'hidden_at');

        return $this->columnsAvailable;
    }

    private function hasColumn(string $table, string $column): bool
    {
        try {
            return $this->db->getSchemaBuilder()->hasColumn($table, $column);
        } catch (Throwable) {
            return false;
        }
    }

    private function assertColumns(): void
    {
        if (! $this->columnsAvailable()) {
            throw new RuntimeException(
                'The hidden_at columns were not found. flarum-auto-dpr requires Flarum 2.0 or newer.'
            );
        }
    }

    private function claimLock(Carbon $now): bool
    {
        $lockedUntil = $this->parseDate($this->config->raw('lock_until'));

        if ($lockedUntil !== null && $lockedUntil->greaterThan($now)) {
            return false;
        }

        $this->writeLock($now);

        return true;
    }

    private function writeLock(Carbon $now): void
    {
        $this->config->set('lock_until', $now->copy()->addMinutes(self::LOCK_MINUTES)->toIso8601String());
    }

    private function releaseLock(): void
    {
        $this->config->set('lock_until', '');
    }

    private function report(string $source, string $status): array
    {
        return [
            'status'            => $status,
            'source'            => $source,
            'discussions'       => 0,
            'posts'             => 0,
            'error'             => null,
            'limit'             => $this->config->batchSize(),
            'retention_minutes' => $this->config->retentionMinutes(),
        ];
    }

    private function parseDate(?string $value): ?Carbon
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (Throwable) {
            return null;
        }
    }
}
