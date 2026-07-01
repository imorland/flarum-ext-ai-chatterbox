import app from 'flarum/forum/app';

export { default as extend } from './extend';

app.initializers.add('ianm-ai-chatterbox', () => {
  console.log('[ianm/ai-chatterbox] Hello, forum!');
});
