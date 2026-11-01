import app from 'flarum/admin/app';
import ExtensionPage from 'flarum/admin/components/ExtensionPage';
import Button from 'flarum/common/components/Button';
import Switch from 'flarum/common/components/Switch';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';

const PREFIX = 'tacraft-sociality-auto-dpr';

const UNITS = [
  ['minutes', '分钟'],
  ['hours', '小时'],
  ['days', '天'],
];

const SETTINGS = {
  enabled: '',
  interval_value: '1',
  interval_unit: 'hours',
  retention_value: '0',
  retention_unit: 'days',
  delete_discussions: '1',
  delete_posts: '1',
  only_author_hidden: '',
  batch_size: '200',
  run_on_request: '1',
};

/**
 * 优先使用语言包文案；若当前语言缺少翻译，则直接使用中文兜底，
 * 保证后台设置在任何语言环境下都是中文。
 */
function trans(key, fallback) {
  const full = PREFIX + '.admin.' + key;

  try {
    const text = app.translator.trans(full);

    if (typeof text === 'string' && text !== full) {
      return text;
    }
  } catch (e) {
    // 忽略
  }

  return fallback;
}

function formatDuration(minutes, zeroText) {
  minutes = Math.max(0, parseInt(minutes, 10) || 0);

  if (minutes === 0) {
    return zeroText || '0 分钟';
  }

  if (minutes % 1440 === 0) {
    return minutes / 1440 + ' 天';
  }

  if (minutes % 60 === 0) {
    return minutes / 60 + ' 小时';
  }

  return minutes + ' 分钟';
}

function formatDate(value) {
  if (!value) {
    return trans('never', '从未运行');
  }

  const date = new Date(value);

  if (isNaN(date.getTime())) {
    return value;
  }

  const pad = (n) => String(n).padStart(2, '0');

  return (
    date.getFullYear() +
    '-' +
    pad(date.getMonth() + 1) +
    '-' +
    pad(date.getDate()) +
    ' ' +
    pad(date.getHours()) +
    ':' +
    pad(date.getMinutes())
  );
}

function sourceLabel(source) {
  switch (source) {
    case 'request':
      return trans('source_request', '访问触发');
    case 'scheduler':
      return trans('source_scheduler', '计划任务');
    case 'cli':
      return trans('source_cli', '控制台命令');
    case 'manual':
      return trans('source_manual', '后台手动');
    default:
      return '';
  }
}

export default class AutoDprPage extends ExtensionPage {
  oninit(vnode) {
    super.oninit(vnode);

    Object.keys(SETTINGS).forEach((key) => {
      this.setting(PREFIX + '.' + key, SETTINGS[key]);
    });

    this.status = null;
    this.statusError = null;
    this.loadingStatus = true;
    this.running = false;

    this.loadStatus();
  }

  content() {
    return m('.ExtensionPage-settings', m('.container', [
      m('.AutoDprSettings', [
        this.statusSection(),
        this.scheduleSection(),
        this.scopeSection(),
        this.advancedSection(),
        m('.Form-group.Form-controls', [
          this.submitButton(),
          m(
            Button,
            {
              className: 'Button',
              icon: 'fas fa-broom',
              loading: this.running,
              disabled: this.running,
              onclick: this.runNow.bind(this),
            },
            trans('run_now_button', '立即执行一次')
          ),
        ]),
        m('p.helpText', trans(
          'run_now_help',
          '“立即执行一次”会忽略启用开关与清理间隔，马上执行一次清理，请谨慎操作。'
        )),
      ]),
    ]));
  }

  // ---------------------------------------------------------------- 区块

  section(titleKey, titleFallback, children) {
    return m('.AutoDprSettings-section', [
      m('h3', trans(titleKey, titleFallback)),
      m('.AutoDprSettings-sectionBody', children),
    ]);
  }

  card(label, value, hint) {
    return m('.AutoDprSettings-card', [
      m('.AutoDprSettings-cardLabel', label),
      m('.AutoDprSettings-cardValue', value),
      hint ? m('.AutoDprSettings-cardHint', hint) : null,
    ]);
  }

  statusSection() {
    const status = this.status;

    const children = [];

    if (this.loadingStatus) {
      children.push(m(LoadingIndicator, { display: 'block' }));
    } else if (this.statusError) {
      children.push(
        m('.Alert.Alert--error', [
          m('p', trans('status_load_failed', '无法读取运行状态：') + ' ' + this.statusError),
        ])
      );
    } else if (status) {
      if (status.columns_available === false) {
        children.push(
          m('.Alert.Alert--error', m('p', trans(
            'columns_missing',
            '当前数据库缺少 hidden_at / hidden_user_id 字段，无法执行自动清理，请确认站点已运行 Flarum 2.0 或更高版本。'
          )))
        );
      }

      children.push(m('.AutoDprSettings-grid', [
        this.card(
          trans('stat_status', '当前状态'),
          status.enabled
            ? trans('status_enabled', '已启用')
            : trans('status_disabled', '已关闭'),
          status.enabled
            ? trans('stat_interval_hint', '每') + formatDuration(status.interval_minutes) + trans('stat_run_suffix', '清理一次')
            : trans('disabled_note', '自动清理已关闭，不会删除任何内容。')
        ),
        this.card(
          trans('stat_pending_discussions', '待删除讨论'),
          String(status.pending_discussions || 0),
          trans('stat_pending_unit_discussion', '条主题（含其全部回复）')
        ),
        this.card(
          trans('stat_pending_posts', '待删除回复'),
          String(status.pending_posts || 0),
          trans('stat_pending_unit_post', '条独立回复')
        ),
        this.card(
          trans('stat_last_run', '上次运行'),
          formatDate(status.last_run_at),
          status.last_run_source
            ? trans('stat_last_source', '触发方式：') + sourceLabel(status.last_run_source)
            : null
        ),
        this.card(
          trans('stat_last_result', '上次删除'),
          trans('stat_last_result_value', '讨论') +
            ' ' +
            (status.last_deleted_discussions || 0) +
            ' / ' +
            trans('stat_last_result_post', '回复') +
            ' ' +
            (status.last_deleted_posts || 0),
          trans('stat_last_result_hint', '累积统计自上次运行')
        ),
        this.card(
          trans('stat_next_run', '下次运行'),
          status.enabled ? formatDate(status.next_run_at) : trans('status_disabled', '已关闭'),
          status.enabled && status.due
            ? trans('stat_due_now', '已到执行时间，等待下一个请求或计划任务')
            : null
        ),
      ]));

      children.push(
        m('p.helpText', trans(
          'scheduler_note',
          '提示：建议在服务器上添加计划任务，每分钟执行一次 php flarum schedule:run。未配置计划任务时，请保持“访问时自动检查”开启，系统会在有人访问论坛时按间隔自动清理。'
        ))
      );
    }

    return this.section('status_heading', '运行状态', children);
  }

  scheduleSection() {
    return this.section('schedule_heading', '定时设置', [
      m('p.helpText', trans('schedule_intro', '自动永久删除被用户删除（隐藏）的讨论和回复，删除后无法恢复。')),
      this.toggle(
        'enabled',
        trans('enabled_switch', '启用自动清理'),
        trans('enabled_help', '开启后，系统会按下面的间隔自动永久删除被用户删除的讨论和回复；关闭后不会删除任何内容。')
      ),
      this.durationField(
        'interval',
        trans('interval_label', '清理间隔'),
        '1',
        trans('interval_help', '每隔多久执行一次自动清理。例如设置为 1 小时，则每小时检查并删除一次。')
      ),
      this.durationField(
        'retention',
        trans('retention_label', '保留时长'),
        '0',
        trans('retention_help', '内容被用户删除后，需要等待多久才会被永久删除。设为 0 表示下一次清理时立即删除。')
      ),
      m('p.AutoDprSettings-summary', this.summaryText()),
    ]);
  }

  scopeSection() {
    return this.section('scope_heading', '删除范围', [
      this.toggle(
        'delete_discussions',
        trans('delete_discussions_switch', '删除被用户删除的讨论'),
        trans('delete_discussions_help', '永久删除整个主题及其全部回复。')
      ),
      this.toggle(
        'delete_posts',
        trans('delete_posts_switch', '删除被用户删除的回复'),
        trans('delete_posts_help', '永久删除单独的回复。若回复所在主题也已被删除，会随主题一起删除。')
      ),
      this.toggle(
        'only_author_hidden',
        trans('only_author_hidden_switch', '仅处理用户自己删除的内容'),
        trans('only_author_hidden_help', '开启后只处理作者本人删除的内容；由管理员或审核隐藏、屏蔽的内容不会被永久删除。')
      ),
    ]);
  }

  advancedSection() {
    return this.section('advanced_heading', '高级选项', [
      m('.Form-group', [
        m('label', trans('batch_size_label', '单次最多处理数量')),
        m('input.FormControl', {
          type: 'number',
          min: '1',
          max: '1000',
          bidi: this.setting(PREFIX + '.batch_size'),
        }),
        m('p.helpText', trans('batch_size_help', '每次运行最多永久删除多少条内容，避免一次删除过多影响数据库性能。取值范围 1 ~ 1000。')),
      ]),
      this.toggle(
        'run_on_request',
        trans('run_on_request_switch', '访问时自动检查'),
        trans('run_on_request_help', '若站点未配置系统计划任务，请开启此项：当有用户访问论坛时自动检查是否到达间隔，到达则执行一次清理。')
      ),
    ]);
  }

  // ---------------------------------------------------------------- 组件

  toggle(key, label, help) {
    const stream = this.setting(PREFIX + '.' + key);

    return m('.Form-group', [
      m(
        Switch,
        {
          state: stream() === '1',
          onchange: (value) => {
            stream(value ? '1' : '');
            m.redraw();
          },
        },
        label
      ),
      help ? m('p.helpText', help) : null,
    ]);
  }

  durationField(prefix, label, min, help) {
    const valueKey = PREFIX + '.' + prefix + '_value';
    const unitKey = PREFIX + '.' + prefix + '_unit';
    const defaultUnit = prefix === 'interval' ? 'hours' : 'days';

    return m('.Form-group', [
      m('label', label),
      m('.AutoDprSettings-inline', [
        m('input.FormControl.AutoDprSettings-number', {
          type: 'number',
          min: min,
          bidi: this.setting(valueKey),
        }),
        m(
          'select.FormControl.AutoDprSettings-unit',
          {
            value: this.setting(unitKey, defaultUnit)(),
            onchange: (e) => {
              this.setting(unitKey)(e.target.value);
              m.redraw();
            },
          },
          UNITS.map(([value, text]) => m('option', { value }, text))
        ),
      ]),
      help ? m('p.helpText', help) : null,
    ]);
  }

  // ---------------------------------------------------------------- 数据

  currentMinutes(prefix, defaultUnit) {
    const value = Math.max(0, parseInt(this.setting(PREFIX + '.' + prefix + '_value')(), 10) || 0);
    const unit = this.setting(PREFIX + '.' + prefix + '_unit')(defaultUnit);
    const factor = unit === 'minutes' ? 1 : unit === 'hours' ? 60 : 1440;

    return value * factor;
  }

  summaryText() {
    const interval = Math.max(1, this.currentMinutes('interval', 'hours'));
    const retention = this.currentMinutes('retention', 'days');

    const intervalText = trans('summary_interval', '当前设置：每 {interval}清理一次').replace(
      '{interval}',
      formatDuration(interval)
    );

    const retentionText =
      retention === 0
        ? trans('summary_retention_zero', '，内容被删除后将在下一次清理时永久删除。')
        : trans('summary_retention', '，内容被删除后 {retention}永久删除。').replace(
            '{retention}',
            formatDuration(retention)
          );

    return intervalText + retentionText;
  }

  loadStatus() {
    this.loadingStatus = true;

    app
      .request({
        method: 'GET',
        url: app.forum.attribute('apiUrl') + '/auto-dpr/status',
      })
      .then(
        (response) => {
          this.status = response.data || response;
          this.statusError = null;
          this.loadingStatus = false;
          m.redraw();
        },
        (error) => {
          this.statusError = (error && error.message) || String(error);
          this.loadingStatus = false;
          m.redraw();
        }
      );
  }

  runNow() {
    if (this.running) {
      return;
    }

    this.running = true;
    m.redraw();

    app
      .request({
        method: 'POST',
        url: app.forum.attribute('apiUrl') + '/auto-dpr/run',
      })
      .then(
        (response) => {
          this.running = false;

          const result = response.data || response || {};

          app.alerts.show(
            { type: 'success' },
            trans('run_done', '已执行：永久删除讨论 {discussions} 条、回复 {posts} 条。')
              .replace('{discussions}', String(result.discussions || 0))
              .replace('{posts}', String(result.posts || 0))
          );

          this.loadStatus();
        },
        (error) => {
          this.running = false;

          app.alerts.show(
            { type: 'error' },
            trans('run_failed', '执行失败，请查看站点日志后重试。')
          );

          m.redraw();
        }
      );
  }
}
