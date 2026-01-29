/**
 * PM2 Ecosystem Configuration
 * Use: pm2 start ecosystem.config.js
 */

module.exports = {
    apps: [{
        name: 'whatsapp-osticket',
        script: 'src/index.js',
        instances: 1,
        autorestart: true,
        watch: false,
        max_memory_restart: '500M',
        env: {
            NODE_ENV: 'production',
            PORT: 3000,
            HOST: '127.0.0.1'
        },
        error_file: 'logs/error.log',
        out_file: 'logs/out.log',
        log_file: 'logs/combined.log',
        time: true
    }]
};
