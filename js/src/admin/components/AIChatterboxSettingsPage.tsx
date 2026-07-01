import app from 'flarum/admin/app';
import ExtensionPage from 'flarum/admin/components/ExtensionPage';
import type Mithril from 'mithril';

export default class AIChatterboxSettingsPage extends ExtensionPage {
  content() {
    return (
      <div className="ExtensionPage-settings">
        <div className="container">
          <form>
            {this.buildSettingComponent({
              type: 'switch',
              setting: 'ianm-ai-chatterbox.enabled',
              label: app.translator.trans('ianm-ai-chatterbox.admin.settings.enabled_label'),
              help: app.translator.trans('ianm-ai-chatterbox.admin.settings.enabled_help'),
            })}

            <h3>{app.translator.trans('ianm-ai-chatterbox.admin.settings.openai_heading')}</h3>

            {this.buildSettingComponent({
              type: 'password',
              setting: 'ianm-ai-chatterbox.api_key',
              label: app.translator.trans('ianm-ai-chatterbox.admin.settings.api_key_label'),
              help: app.translator.trans('ianm-ai-chatterbox.admin.settings.api_key_help'),
            })}

            {this.buildSettingComponent({
              type: 'text',
              setting: 'ianm-ai-chatterbox.model',
              label: app.translator.trans('ianm-ai-chatterbox.admin.settings.model_label'),
              help: app.translator.trans('ianm-ai-chatterbox.admin.settings.model_help'),
            })}

            {this.buildSettingComponent({
              type: 'textarea',
              setting: 'ianm-ai-chatterbox.prompt',
              label: app.translator.trans('ianm-ai-chatterbox.admin.settings.prompt_label'),
              help: app.translator.trans('ianm-ai-chatterbox.admin.settings.prompt_help'),
            })}

            <h3>{app.translator.trans('ianm-ai-chatterbox.admin.settings.topics_heading')}</h3>

            {this.buildSettingComponent({
              type: 'textarea',
              setting: 'ianm-ai-chatterbox.feed_urls',
              label: app.translator.trans('ianm-ai-chatterbox.admin.settings.feed_urls_label'),
              help: app.translator.trans('ianm-ai-chatterbox.admin.settings.feed_urls_help'),
            })}

            {this.buildSettingComponent({
              type: 'number',
              setting: 'ianm-ai-chatterbox.news_share',
              label: app.translator.trans('ianm-ai-chatterbox.admin.settings.news_share_label'),
              help: app.translator.trans('ianm-ai-chatterbox.admin.settings.news_share_help'),
              min: 0,
              max: 1,
              step: 0.05,
            })}

            <h3>{app.translator.trans('ianm-ai-chatterbox.admin.settings.behaviour_heading')}</h3>

            {this.buildSettingComponent({
              type: 'number',
              setting: 'ianm-ai-chatterbox.user_count',
              label: app.translator.trans('ianm-ai-chatterbox.admin.settings.user_count_label'),
              help: app.translator.trans('ianm-ai-chatterbox.admin.settings.user_count_help'),
              min: 0,
            })}

            {this.buildSettingComponent({
              type: 'number',
              setting: 'ianm-ai-chatterbox.frequency',
              label: app.translator.trans('ianm-ai-chatterbox.admin.settings.frequency_label'),
              help: app.translator.trans('ianm-ai-chatterbox.admin.settings.frequency_help'),
              min: 0,
            })}

            {this.buildSettingComponent({
              type: 'flarum-tags.select-tags',
              setting: 'ianm-ai-chatterbox.enabled_tags',
              label: app.translator.trans('ianm-ai-chatterbox.admin.settings.enabled_tags_label'),
              help: app.translator.trans('ianm-ai-chatterbox.admin.settings.enabled_tags_help'),
            })}

            <h3>{app.translator.trans('ianm-ai-chatterbox.admin.settings.activity_heading')}</h3>

            <p className="helpText">{app.translator.trans('ianm-ai-chatterbox.admin.settings.activity_help')}</p>

            {this.buildSettingComponent({
              type: 'switch',
              setting: 'ianm-ai-chatterbox.enable_discussions',
              label: app.translator.trans('ianm-ai-chatterbox.admin.settings.enable_discussions_label'),
            })}

            {this.buildSettingComponent({
              type: 'number',
              setting: 'ianm-ai-chatterbox.weight_discussions',
              label: app.translator.trans('ianm-ai-chatterbox.admin.settings.weight_discussions_label'),
              min: 0,
            })}

            {this.buildSettingComponent({
              type: 'switch',
              setting: 'ianm-ai-chatterbox.enable_replies',
              label: app.translator.trans('ianm-ai-chatterbox.admin.settings.enable_replies_label'),
            })}

            {this.buildSettingComponent({
              type: 'number',
              setting: 'ianm-ai-chatterbox.weight_replies',
              label: app.translator.trans('ianm-ai-chatterbox.admin.settings.weight_replies_label'),
              min: 0,
            })}

            {this.buildSettingComponent({
              type: 'switch',
              setting: 'ianm-ai-chatterbox.enable_likes',
              label: app.translator.trans('ianm-ai-chatterbox.admin.settings.enable_likes_label'),
            })}

            {this.buildSettingComponent({
              type: 'number',
              setting: 'ianm-ai-chatterbox.weight_likes',
              label: app.translator.trans('ianm-ai-chatterbox.admin.settings.weight_likes_label'),
              min: 0,
            })}

            <h3>{app.translator.trans('ianm-ai-chatterbox.admin.settings.conversation_heading')}</h3>

            <p className="helpText">{app.translator.trans('ianm-ai-chatterbox.admin.settings.conversation_help')}</p>

            {this.buildSettingComponent({
              type: 'number',
              setting: 'ianm-ai-chatterbox.conversation_continue_chance',
              label: app.translator.trans('ianm-ai-chatterbox.admin.settings.conversation_continue_chance_label'),
              help: app.translator.trans('ianm-ai-chatterbox.admin.settings.conversation_continue_chance_help'),
              min: 0,
              max: 1,
              step: 0.05,
            })}

            {this.buildSettingComponent({
              type: 'number',
              setting: 'ianm-ai-chatterbox.conversation_max_depth',
              label: app.translator.trans('ianm-ai-chatterbox.admin.settings.conversation_max_depth_label'),
              help: app.translator.trans('ianm-ai-chatterbox.admin.settings.conversation_max_depth_help'),
              min: 1,
            })}

            {this.buildSettingComponent({
              type: 'number',
              setting: 'ianm-ai-chatterbox.max_replies_per_thread',
              label: app.translator.trans('ianm-ai-chatterbox.admin.settings.max_replies_per_thread_label'),
              help: app.translator.trans('ianm-ai-chatterbox.admin.settings.max_replies_per_thread_help'),
              min: 1,
            })}

            {this.buildSettingComponent({
              type: 'switch',
              setting: 'ianm-ai-chatterbox.busy_thread_gate',
              label: app.translator.trans('ianm-ai-chatterbox.admin.settings.busy_thread_gate_label'),
              help: app.translator.trans('ianm-ai-chatterbox.admin.settings.busy_thread_gate_help'),
            })}

            <h3>{app.translator.trans('ianm-ai-chatterbox.admin.settings.active_hours_heading')}</h3>

            <p className="helpText">{app.translator.trans('ianm-ai-chatterbox.admin.settings.active_hours_help')}</p>

            {this.buildSettingComponent({
              type: 'time',
              setting: 'ianm-ai-chatterbox.active_start',
              label: app.translator.trans('ianm-ai-chatterbox.admin.settings.active_start_label'),
            })}

            {this.buildSettingComponent({
              type: 'time',
              setting: 'ianm-ai-chatterbox.active_end',
              label: app.translator.trans('ianm-ai-chatterbox.admin.settings.active_end_label'),
            })}

            <h3>{app.translator.trans('ianm-ai-chatterbox.admin.settings.realism_heading')}</h3>

            {this.buildSettingComponent({
              type: 'switch',
              setting: 'ianm-ai-chatterbox.simulate_typing',
              label: app.translator.trans('ianm-ai-chatterbox.admin.settings.simulate_typing_label'),
              help: app.translator.trans('ianm-ai-chatterbox.admin.settings.simulate_typing_help'),
            })}

            {this.buildSettingComponent({
              type: 'number',
              setting: 'ianm-ai-chatterbox.delay_min',
              label: app.translator.trans('ianm-ai-chatterbox.admin.settings.delay_min_label'),
              help: app.translator.trans('ianm-ai-chatterbox.admin.settings.delay_help'),
              min: 0,
            })}

            {this.buildSettingComponent({
              type: 'number',
              setting: 'ianm-ai-chatterbox.delay_max',
              label: app.translator.trans('ianm-ai-chatterbox.admin.settings.delay_max_label'),
              min: 0,
            })}

            {this.submitButton()}
          </form>
        </div>
      </div>
    );
  }

  oninit(vnode: Mithril.Vnode<this['attrs'], this>) {
    super.oninit(vnode);
  }
}
