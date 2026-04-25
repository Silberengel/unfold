import { startStimulusApp } from '@symfony/stimulus-bundle';
import ArticleCommentsController from './controllers/article_comments_controller.js';
import CommentReplyController from './controllers/comment_reply_controller.js';
import CopyTextController from './controllers/copy_text_controller.js';
import ArticleHighlightController from './controllers/article_highlight_controller.js';

const app = startStimulusApp();

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
    app.register('article-highlight', ArticleHighlightController);
} catch {
    /* already registered by the bundle */
}
