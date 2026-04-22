import { startStimulusApp } from '@symfony/stimulus-bundle';
import ArticleCommentsController from './controllers/article_comments_controller.js';
import MagazineSyncController from './controllers/magazine_sync_controller.js';

const app = startStimulusApp();

// Ensure lazy comment loader is registered (Asset Mapper discovery can miss new files until rebuild).
try {
    app.register('article-comments', ArticleCommentsController);
} catch {
    /* already registered by the bundle */
}
try {
    app.register('magazine-sync', MagazineSyncController);
} catch {
    /* already registered by the bundle */
}
