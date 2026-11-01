<?php

namespace TacraftSociality\AutoDpr;

use Flarum\Settings\SettingsRepositoryInterface;

/**
 * 读取（并缓存于设置仓库中的）扩展配置。
 */
class Config
{
    public const PREFIX = 'tacraft-sociality-auto-dpr';

    /** 单位 => 分钟数 */
    private const UNIT_FACTORS = [
        'minutes' => 1,
        'hours'   => 60,
        'days'    => 1440,
    ];

    public function __construct(private readonly SettingsRepositoryInterface $settings)
    {
    }

    public static function key(string $key): string
    {
        return self::PREFIX.'.'.$key;
    }

    public static function units(): array
    {
        return array_keys(self::UNIT_FACTORS);
    }

    public function raw(string $key): ?string
    {
        $value = $this->settings->get(self::key($key));

        return $value === null ? null : (string) $value;
    }

    public function bool(string $key, bool $default = false): bool
    {
        $value = $this->raw($key);

        if ($value === null || trim($value) === '') {
            return $default;
        }

        return in_array(strtolower(trim($value)), ['1', 'true', 'yes', 'on'], true);
    }

    public function int(string $key, int $default = 0): int
    {
        $value = $this->raw($key);

        if ($value === null || trim($value) === '') {
            return $default;
        }

        return (int) $value;
    }

    public function unit(string $key, string $default = 'days'): string
    {
        $value = strtolower(trim((string) $this->raw($key)));

        return isset(self::UNIT_FACTORS[$value]) ? $value : $default;
    }

    public function enabled(): bool
    {
        return $this->bool('enabled');
    }

    public function runOnRequest(): bool
    {
        return $this->bool('run_on_request', true);
    }

    public function deleteDiscussions(): bool
    {
        return $this->bool('delete_discussions', true);
    }

    public function deletePosts(): bool
    {
        return $this->bool('delete_posts', true);
    }

    public function onlyAuthorHidden(): bool
    {
        return $this->bool('only_author_hidden');
    }

    /**
     * 两次自动清理之间的间隔（分钟），最少 1 分钟、最多 7 天。
     */
    public function intervalMinutes(): int
    {
        $minutes = $this->int('interval_value', 1) * self::UNIT_FACTORS[$this->unit('interval_unit', 'hours')];

        return max(1, min(10080, $minutes));
    }

    /**
     * 内容被删除（隐藏）后，需要等待多久才永久删除（分钟）。
     * 0 表示下一次运行就删除。
     */
    public function retentionMinutes(): int
    {
        $minutes = $this->int('retention_value', 0) * self::UNIT_FACTORS[$this->unit('retention_unit', 'days')];

        return max(0, min(525600, $minutes));
    }

    /**
     * 单次运行最多处理多少条内容（讨论按条计，回复按条计）。
     */
    public function batchSize(): int
    {
        return max(1, min(1000, $this->int('batch_size', 200)));
    }

    public function set(string $key, string $value): void
    {
        $this->settings->set(self::key($key), $value);
    }
}
