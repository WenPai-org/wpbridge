# Tab 重构：项目 → 更新管理 + 自定义更新源迁移

## 背景

"自定义更新源"列表放在供应商 Tab 下，与供应商概念无关；"默认规则"三层拖拽排序过度设计。两者让用户困惑。

## 设计

### 结构变化

- "项目" Tab 重命名为"更新管理"
- "默认规则" subtab 替换为"更新源" subtab
- 供应商 Tab 删除"自定义更新源"折叠区块

### "更新源" subtab

扁平表格展示所有注册源（供应商自动注册 + 用户手动添加），含：名称、类型标签、启用/禁用 toggle、编辑/删除操作。供应商源不可编辑/删除。顶部有 [添加更新源] 按钮。

### 改动文件

| 文件 | 改动 |
|------|------|
| `templates/admin/main.php` | "项目" → "更新管理" |
| `templates/admin/tabs/projects.php` | "默认规则" subtab → "更新源" |
| `templates/admin/partials/sources-list.php` | 新建，更新源列表 |
| `templates/admin/tabs/vendors.php` | 删除自定义更新源折叠区 |
| `assets/js/admin.js` | 删除 defaults 表单代码 |

### 不动

后端逻辑（SourceRegistry、ItemSourceManager、AJAX 端点）全部不变。
