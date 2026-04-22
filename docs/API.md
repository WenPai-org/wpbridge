# WPBridge API 文档

> Bridge API - REST API 接口文档

## 概述

WPBridge 提供 REST API 供外部系统调用，用于获取插件状态、管理更新源等。

## 基础信息

- **基础 URL**: `/wp-json/bridge/v1/`
- **认证方式**: API Key
- **响应格式**: JSON

## 认证

### 获取 API Key

1. 登录 WordPress 后台
2. 进入「设置 > WPBridge > API」
3. 点击「生成 API Key」
4. 保存生成的 Key（只显示一次）

### 使用 API Key

在请求头中添加：

```http
X-WPBridge-Key: your_api_key_here
```

或使用查询参数：

```
?api_key=your_api_key_here
```

## API 端点

### 获取状态

获取 WPBridge 插件的运行状态。

```http
GET /wp-json/bridge/v1/status
```

#### 响应示例

```json
{
  "success": true,
  "data": {
    "version": "0.8.0",
    "sources_count": 5,
    "enabled_sources": 4,
    "last_check": "2026-02-05 10:30:00",
    "cache_status": "healthy"
  }
}
```

### 获取更新源列表

获取所有配置的更新源。

```http
GET /wp-json/bridge/v1/sources
```

#### 响应示例

```json
{
  "success": true,
  "data": {
    "sources": [
      {
        "id": "source_abc123",
        "name": "My Update Source",
        "type": "json",
        "enabled": true,
        "priority": 10,
        "last_check": "2026-02-05 10:00:00",
        "status": "healthy"
      }
    ]
  }
}
```

### 检查更新源

检查指定更新源的连通性。

```http
POST /wp-json/bridge/v1/sources/{source_id}/check
```

#### 响应示例

```json
{
  "success": true,
  "data": {
    "status": "healthy",
    "response_time": 0.234,
    "last_check": "2026-02-05 10:30:00"
  }
}
```

### 获取插件信息

获取指定插件的更新信息。

```http
GET /wp-json/bridge/v1/plugins/{slug}/info
```

#### 参数

| 参数 | 类型 | 说明 |
|------|------|------|
| slug | string | 插件 slug |

#### 响应示例

```json
{
  "success": true,
  "data": {
    "name": "Example Plugin",
    "slug": "example-plugin",
    "version": "1.2.3",
    "requires": "5.9",
    "tested": "6.4",
    "download_url": "https://example.com/plugin.zip"
  }
}
```

### 获取主题信息

获取指定主题的更新信息。

```http
GET /wp-json/bridge/v1/themes/{slug}/info
```

#### 参数

| 参数 | 类型 | 说明 |
|------|------|------|
| slug | string | 主题 slug |

## 错误响应

### 错误格式

```json
{
  "success": false,
  "data": {
    "code": "error_code",
    "message": "错误描述"
  }
}
```

### 错误代码

| 代码 | HTTP 状态 | 说明 |
|------|-----------|------|
| `unauthorized` | 401 | 未提供或无效的 API Key |
| `forbidden` | 403 | 权限不足 |
| `not_found` | 404 | 资源不存在 |
| `invalid_request` | 400 | 请求参数无效 |
| `server_error` | 500 | 服务器内部错误 |

## 速率限制

- 默认限制：60 请求/分钟
- 超出限制返回 429 状态码

## 示例代码

### cURL

```bash
curl -X GET \
  'https://example.com/wp-json/bridge/v1/status' \
  -H 'X-WPBridge-Key: your_api_key'
```

### PHP

```php
$response = wp_remote_get( 'https://example.com/wp-json/bridge/v1/status', [
    'headers' => [
        'X-WPBridge-Key' => 'your_api_key',
    ],
] );

$data = json_decode( wp_remote_retrieve_body( $response ), true );
```

### JavaScript

```javascript
fetch('https://example.com/wp-json/bridge/v1/status', {
    headers: {
        'X-WPBridge-Key': 'your_api_key'
    }
})
.then(response => response.json())
.then(data => console.log(data));
```

---

*最后更新: 2026-02-05*
