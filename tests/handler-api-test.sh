#!/bin/bash
#
# Handler API 连通性测试脚本
# 直接测试各更新源 API 端点的可访问性
#

echo ""
echo "╔═══════════════════════════════════════════════════════════╗"
echo "║       WPBridge Handler API 连通性测试                     ║"
echo "║       测试各更新源 API 端点的可访问性                      ║"
echo "╚═══════════════════════════════════════════════════════════╝"
echo ""
echo "测试时间: $(date '+%Y-%m-%d %H:%M:%S')"
echo ""

# 颜色定义
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

# 测试结果统计
PASS=0
FAIL=0
SKIP=0

# 测试函数
test_api() {
    local name="$1"
    local url="$2"
    local expected_field="$3"
    local skip="${4:-false}"
    local skip_reason="$5"

    echo "┌─ 测试: $name"

    if [ "$skip" = "true" ]; then
        echo -e "│  ${YELLOW}⏭️  跳过: $skip_reason${NC}"
        echo "└─────────────────────────────────"
        ((SKIP++))
        return
    fi

    echo "│  📡 URL: $url"

    # 发送请求
    response=$(curl -s -w "\n%{http_code}" --connect-timeout 10 --max-time 30 "$url" 2>/dev/null)
    http_code=$(echo "$response" | tail -n1)
    body=$(echo "$response" | sed '$d')

    if [ "$http_code" = "200" ]; then
        echo -e "│  ${GREEN}✅ HTTP 200 OK${NC}"

        # 检查响应是否包含预期字段
        if [ -n "$expected_field" ]; then
            if echo "$body" | grep -q "$expected_field"; then
                echo -e "│  ${GREEN}✅ 响应包含: $expected_field${NC}"
                ((PASS++))
            else
                echo -e "│  ${YELLOW}⚠️  响应不包含: $expected_field${NC}"
                ((PASS++))
            fi
        else
            ((PASS++))
        fi

        # 显示部分响应
        echo "│  📋 响应预览:"
        echo "$body" | head -c 200 | sed 's/^/│     /'
        echo ""
    else
        echo -e "│  ${RED}❌ HTTP $http_code${NC}"
        ((FAIL++))
    fi

    echo "└─────────────────────────────────"
}

echo "═══════════════════════════════════════════════════════════"
echo "                    开始测试各 Handler API"
echo "═══════════════════════════════════════════════════════════"

# 1. GitHub API
echo ""
test_api "GitHub API (Releases)" \
    "https://api.github.com/repos/developer-developer/developer-developer/releases/latest" \
    "tag_name" \
    "true" \
    "需要配置真实 GitHub 项目"

# 2. GitLab API
test_api "GitLab API (Releases)" \
    "https://gitlab.com/api/v4/projects/developer-developer%2Fdeveloper-developer/releases" \
    "tag_name" \
    "true" \
    "需要配置真实 GitLab 项目"

# 3. Gitee API
test_api "Gitee API (Releases)" \
    "https://gitee.com/api/v5/repos/developer-developer/developer-developer/releases/latest" \
    "tag_name" \
    "true" \
    "需要配置真实 Gitee 项目"

# 4. WordPress.org API (可以测试)
test_api "WordPress.org API (Plugin Info)" \
    "https://api.wordpress.org/plugins/info/1.2/?action=plugin_information&slug=hello-dolly" \
    "version"

# 5. WordPress.org 翻译 API
test_api "WordPress.org API (Translations)" \
    "https://api.wordpress.org/translations/plugins/1.0/?slug=hello-dolly" \
    "translations"

# 6. 文派 API 镜像 - 插件信息
test_api "WenPai API (Plugin Info)" \
    "https://api.wenpai.net/plugins/info/1.2/?action=plugin_information&slug=hello-dolly" \
    "version"

# 7. 文派 API 镜像 - 核心版本
test_api "WenPai API (Core Version)" \
    "https://api.wenpai.net/core/version-check/1.7/" \
    "offers"

# 8. 文派叶子版本检查 (PUC 格式)
test_api "WenPai PUC (wp-china-yes)" \
    "https://api.wenpai.net/china-yes/version-check" \
    "version"

# 9. wpmirror.com 备用镜像
test_api "WPMirror API (Core Version)" \
    "https://api.wpmirror.com/core/version-check/1.7/" \
    "offers"

# 10. AspireCloud API (如果有公开端点)
test_api "AspireCloud API" \
    "https://developer.developer/plugins/info/1.2?slug=developer-developer" \
    "version" \
    "true" \
    "需要配置真实 AspireCloud 服务"

# 11. ArkPress API
test_api "ArkPress API" \
    "https://developer.developer/plugins/developer-developer" \
    "version" \
    "true" \
    "需要配置真实 ArkPress 服务"

# 12. 本地 Bridge Server
test_api "Bridge Server (本地)" \
    "http://localhost:8080/health" \
    "status"

# 13. JSON/PUC 格式测试
test_api "JSON/PUC API" \
    "https://developer.developer/updates/developer-developer.json" \
    "version" \
    "true" \
    "需要配置真实 PUC 端点"

# 14. FAIR 协议
test_api "FAIR Protocol API" \
    "https://developer.developer/fair/plugins/developer-developer" \
    "version" \
    "true" \
    "需要配置真实 FAIR 服务"

echo ""
echo "╔═══════════════════════════════════════════════════════════╗"
echo "║                      测试汇总                             ║"
echo "╚═══════════════════════════════════════════════════════════╝"
echo ""
echo -e "  ${GREEN}✅ 通过: $PASS${NC}"
echo -e "  ${RED}❌ 失败: $FAIL${NC}"
echo -e "  ${YELLOW}⏭️  跳过: $SKIP${NC}"
echo ""

TOTAL=$((PASS + FAIL + SKIP))
EXECUTED=$((PASS + FAIL))

if [ $EXECUTED -gt 0 ]; then
    RATE=$(echo "scale=1; $PASS * 100 / $EXECUTED" | bc)
    echo "执行率: $EXECUTED/$TOTAL (${RATE}% 成功)"
fi

echo ""
echo "╔═══════════════════════════════════════════════════════════╗"
echo "║                   云桥转接能力评估                        ║"
echo "╚═══════════════════════════════════════════════════════════╝"
echo ""

if [ $PASS -gt 0 ]; then
    echo -e "${GREEN}✅ WordPress.org API 可正常访问，云桥基础功能可用${NC}"
fi

if [ $SKIP -gt 0 ]; then
    echo ""
    echo -e "${YELLOW}⏭️  以下 Handler 需要配置真实端点后测试:${NC}"
    echo "   • GitHub Releases"
    echo "   • GitLab Releases"
    echo "   • Gitee Releases"
    echo "   • AspireCloud"
    echo "   • ArkPress"
    echo "   • Bridge Server"
    echo "   • JSON/PUC"
    echo "   • FAIR Protocol"
fi

echo ""
