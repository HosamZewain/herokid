import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/css/front-theme.css', 'resources/css/homepage.css', 'resources/css/app-download.css', 'resources/js/app.js', 'resources/js/order-workspace.js', 'resources/js/app-download.js'],
            refresh: true,
        }),
    ],
});
