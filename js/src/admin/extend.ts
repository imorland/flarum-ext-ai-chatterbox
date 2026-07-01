import Extend from 'flarum/common/extenders';
import commonExtend from '../common/extend';
import AIChatterboxSettingsPage from './components/AIChatterboxSettingsPage';

export default [
  ...commonExtend,

  new Extend.Admin() //
    .page(AIChatterboxSettingsPage),
];
