# api-admin — Arelonne Admin API（项目代号 HOPE）

🇺🇸 AWS 部署。为 Arelonne 品牌后台管理系统提供商品管理、订单处理、配置管理。与 api-store 共用同一台 🇺🇸 MySQL（单库单区域，无同步层）。

## 路由

| 方法 | 路径 | 说明 |
|------|------|------|
| POST | /api/admin/login | 管理员登录 |
| GET/POST | /api/admin/products | 商品 CRUD |
| PUT/DELETE | /api/admin/products/{id} | 编辑/删除商品 |
| POST | /api/admin/products/batch-import | 批量导入 |
| GET/POST | /api/admin/categories | 分类 CRUD |
| PUT/DELETE | /api/admin/categories/{id} | 编辑/删除分类 |
| GET | /api/admin/orders | 订单列表 |
| PUT | /api/admin/orders/{id}/status | 更新订单状态 |
| GET/PUT | /api/admin/settings | 站点配置 |

## 管理员

`admin@arelonne.com` / `admin123456`

## 技术栈

Laravel / MySQL / Sanctum

## 启动

```bash
php artisan serve --port=8082
```
