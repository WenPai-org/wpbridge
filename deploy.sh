#!/bin/bash
# WPBridge 部署脚本
# 将开发目录的代码同步到 WordPress 插件目录

set -e

SOURCE_DIR="$(cd "$(dirname "$0")" && pwd)"
TARGET_DIR="/www/wwwroot/wpcy.com/wp-content/plugins/wpbridge"

echo "WPBridge 部署脚本"
echo "========================================"
echo "源目录: $SOURCE_DIR"
echo "目标目录: $TARGET_DIR"
echo ""

# 检查目标目录是否存在
if [ ! -d "$TARGET_DIR" ]; then
    echo "ERROR: 目标目录不存在: $TARGET_DIR"
    exit 1
fi

# 版本一致性检查
SRC_VER=$(grep -oP "define\(\s*'WPBRIDGE_VERSION',\s*'\K[^']+" "$SOURCE_DIR/wpbridge.php")
echo "当前版本: $SRC_VER"

# 同步文件 (排除开发文件)
echo "同步文件..."
sudo rsync -av --delete \
    --exclude='.git' \
    --exclude='.github' \
    --exclude='.forgejo' \
    --exclude='deploy.sh' \
    --exclude='*.log' \
    --exclude='*.md' \
    --exclude='tests' \
    --exclude='docs' \
    --exclude='node_modules' \
    --exclude='composer.*' \
    --exclude='phpcs.*' \
    --exclude='phpunit.*' \
    --exclude='.phpcs*' \
    --exclude='.gitignore' \
    "$SOURCE_DIR/" "$TARGET_DIR/"

# 修复权限
echo "修复权限..."
sudo chown -R www:www "$TARGET_DIR"
sudo chmod -R 644 "$TARGET_DIR"
sudo find "$TARGET_DIR" -type d -exec chmod 755 {} \;

# 清除 OPcache
echo "清除 OPcache..."
cd "$TARGET_DIR"
wp eval 'if(function_exists("opcache_reset")){opcache_reset();echo "OPcache cleared\n";}' --allow-root 2>/dev/null || true

echo ""
echo "部署完成! 版本: $SRC_VER"
echo "========================================"
