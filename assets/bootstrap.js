import { startStimulusApp } from '@symfony/stimulus-bundle';
import ArticleCommentsController from './controllers/article_comments_controller.js';
import CommentReplyController from './controllers/comment_reply_controller.js';
import CopyTextController from './controllers/copy_text_controller.js';
import UserHighlightTooltipController from './controllers/user_highlight_tooltip_controller.js';
import NostrShareMenuController from './controllers/nostr_share_menu_controller.js';
const app = startStimulusApp();
if (typeof app.debug === 'boolean') {
    app.debug = false;
}

// Ensure lazy comment loader is registered (Asset Mapper discovery can miss new files until rebuild).
try {
    app.register('article-comments', ArticleCommentsController);
} catch {
    /* already registered by the bundle */
}
try {
    app.register('comment-reply', CommentReplyController);
} catch {
    /* already registered by the bundle */
}
try {
    app.register('copy-text', CopyTextController);
} catch {
    /* already registered by the bundle */
}
try {
    app.register('user-highlight-tooltip', UserHighlightTooltipController);
} catch {
    /* already registered by the bundle */
}
try {
    app.register('nostr-share-menu', NostrShareMenuController);
} catch {
    /* already registered by the bundle */
}
