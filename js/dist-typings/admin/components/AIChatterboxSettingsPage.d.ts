import ExtensionPage from 'flarum/admin/components/ExtensionPage';
import type Mithril from 'mithril';
export default class AIChatterboxSettingsPage extends ExtensionPage {
    content(): JSX.Element;
    oninit(vnode: Mithril.Vnode<this['attrs'], this>): void;
}
