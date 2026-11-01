import Extend from 'flarum/common/extenders';
import AutoDprPage from './components/AutoDprPage';

export default [
  new Extend.Admin().page(AutoDprPage),
];
