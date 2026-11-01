const config = require('flarum-webpack-config')();

config.entry = {
  admin: './admin.js',
};

module.exports = config;
