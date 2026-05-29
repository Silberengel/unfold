import { startStimulusApp } from '@symfony/stimulus-bundle';
import ArticleCommentsController from './controllers/article_comments_controller.js';
import CommentReplyController from './controllers/comment_reply_controller.js';
import CopyTextController from './controllers/copy_text_controller.js';
import UserHighlightTooltipController from './controllers/user_highlight_tooltip_controller.js';
import NostrShareMenuController from './controllers/nostr_share_menu_controller.js';
import ColorSchemeController from './controllers/color_scheme_controller.js';
import MagazineHierarchyEditorController from './controllers/magazine_hierarchy_editor_controller.js';
import FooterMagazineEditController from './controllers/footer_magazine_edit_controller.js';
import PublicationExportController from './controllers/publication_export_controller.js';
import LoginController from './controllers/login_controller.js';
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
try {
    app.register('color-scheme', ColorSchemeController);
} catch {
    /* already registered by the bundle */
}
try {
    app.register('magazine-hierarchy-editor', MagazineHierarchyEditorController);
} catch (e) {
    console.warn('[bootstrap] magazine-hierarchy-editor did not register; editor buttons will not work.', e);
}
try {
    app.register('footer-magazine-edit', FooterMagazineEditController);
} catch {
    /* already registered by the bundle */
}
try {
    app.register('publication-export', PublicationExportController);
} catch {
    /* already registered by the bundle */
}
try {
    app.register('login', LoginController);
} catch {
    /* already registered by the bundle */
}
