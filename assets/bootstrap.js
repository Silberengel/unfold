import { startStimulusApp } from '@symfony/stimulus-bundle';
import ArticleCommentsController from './controllers/article_comments_controller.js';

const app = startStimulusApp();

// Ensure lazy comment loader is registered (Asset Mapper discovery can miss new files until rebuild).
try {
    app.register('article-comments', ArticleCommentsController);
} catch {
    /* already registered by the bundle */
}
