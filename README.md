# api-admin — HOPE Admin API

🇨🇳 阿里云部署。为后台管理系统提供商品管理、订单处理、配置管理。

## 路由

| 方法 | 路径 | 说明 |
|------|------|------|
| POST | /api/admin/login | 管理员登录 |
| GET/POST | /api/admin/products | 商品 CRUD |
| PUT/DELETE | /api/admin/products/{id} | 编辑/删除商品 |
| POST | /api/admin/products/batch-import | 批量导入 |
| GET | /api/admin/orders | 订单列表 |
| PUT | /api/admin/orders/{id}/status | 更新订单状态 |
| GET/PUT | /api/admin/settings | 站点配置 |
| POST | /api/sync/orders | 接收订单同步 |

## 管理员

`admin@hope.com` / `admin123456`

## 技术栈

Laravel / MySQL / Sanctum

## 启动

```bash
php artisan serve --port=8082
```
