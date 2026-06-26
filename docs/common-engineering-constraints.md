# HOLP 通用工程约束

## 1. 目的

本文件定义 HOLP 项目的工程基线，不绑定具体业务逻辑。

目标是为所有协作者提供一致的实现边界，避免随着迭代推进出现职责混乱、结构失控、代码不可维护的问题。

## 2. 开发原则

### 2.1 模块化优先

每个模块只负责一类明确问题，模块之间通过清晰边界协作。

模块设计优先满足：

1. 高内聚
2. 低耦合
3. 易替换
4. 易扩展

新增能力时，优先通过新增模块或扩展明确模块完成，而不是持续向旧文件堆叠逻辑。

### 2.2 单一职责原则

适用于模块、文件、函数、类：

1. 一个模块只负责一个业务域
2. 一个文件只承载一个清晰主题
3. 一个函数只做一件事
4. 一个类只表达一个核心角色

### 2.3 可读性优先

默认优先选择：

1. 更清晰的命名
2. 更直观的结构
3. 更稳定的抽象
4. 更容易维护的实现

不鼓励：

1. 炫技式写法
2. 过度封装
3. 过早抽象
4. 为了"高级"而牺牲可读性

---

## 3. 目录与文件整洁约束

### 3.1 目录结构必须清晰

任何人打开项目根目录后，应在短时间内理解：

1. 源码在哪里（`api/` + `web/`）
2. 文档在哪里（`docs/`）
3. 入口在哪里（`api/public/index.php` + `web/src/app/`）
4. 数据在哪里（`api/database/`）

### 3.2 根目录约束

根目录只允许：

- 项目配置文件（`docker-compose.yml`、`.env.example`）
- 项目说明文件（`README.md`、`CLAUDE.md`）
- 两个子项目目录（`api/`、`web/`）
- 文档目录（`docs/`）

根目录不允许长期堆放：

- 临时调试脚本
- 一次性实验文件
- 历史备份文件
- 杂乱日志
- 未归类业务文件

### 3.3 临时文件处理

- 临时产物、草稿、调试输出放入 `temp/`（`.gitignore` 中忽略）
- 工具脚本放入 `scripts/`

---

## 4. 代码规范约束

具体的代码书写规范、命名细则、函数/类规则统一以 [coding-standards.md](./coding-standards.md) 为准。

本文件只保留以下通用基线：

1. 代码规范必须文档化，不能靠口头约定
2. 代码规范属于工程治理的一部分，不是个人风格偏好
3. 新增功能时可以在通用基线之上追加细则，但不能破坏通用约束

---

## 5. API 通用设计约束

### 5.1 RESTful 原则

1. 资源导向设计
2. 路径使用名词而非动词
3. 路径使用复数形式
4. HTTP 方法语义必须明确

### 5.2 请求与响应

1. 请求默认统一使用 JSON
2. 成功响应必须结构化（`data` + 可选 `meta`）
3. 错误响应必须结构化（`message` + 可选 `errors`）
4. 数据转换统一使用 Laravel API Resource，不直接暴露 Eloquent Model

### 5.3 验证

1. 所有请求输入在 Controller 层之前完成验证
2. 使用 Laravel FormRequest 做验证逻辑
3. 验证失败返回 422，附带结构化错误信息

### 5.4 鉴权

1. 前台 `/api/store/*` 公开，无需认证
2. 后台 `/api/admin/*` 需要 JWT Token（Bearer Authorization header）
3. Token 过期或无效返回 401

---

## 6. 前后端边界约束

### 6.1 Laravel（后端）职责

- 数据存取和业务逻辑
- API 鉴权和输入验证
- 支付回调处理（第三方 webhook）
- 订单状态机和数据一致性

### 6.2 Next.js（前端）职责

- 页面渲染和路由
- 用户交互和 UI 状态
- 前端校验（UX 友好）
- API 请求封装和错误处理

### 6.3 不能越界

- Laravel 不渲染 HTML 页面（纯 API，无 Blade 视图）
- Next.js Server Component 不直接访问数据库（通过 API 调用）
- 前端不做支付敏感逻辑（价格计算以后端为准）

---

## 7. 测试约束

### 7.1 测试要求

1. 核心业务逻辑必须有单元测试（Laravel PHPUnit）
2. 主要 API 端点必须有集成测试
3. 关键业务流程（下单 → 支付 → 发货）可端到端验证

### 7.2 测试原则

1. 测试要可重复执行
2. 测试数据要可控（使用 Factory + 固定夹具）
3. 测试执行后要可清理（RefreshDatabase trait）
4. 测试不依赖人工操作

---

## 8. 环境与运维约束

### 8.1 环境变量管理

1. `.env` 不提交到版本控制
2. 提供 `.env.example` 作为模板
3. 不允许把真实密钥硬编码进代码
4. 敏感配置（DB 密码、JWT secret、支付 key）必须走环境变量

### 8.2 配置管理

1. 配置支持环境分层（local / production）
2. Laravel 使用 `config/*.php` + `env()` helper
3. Next.js 使用 `.env.local` + `process.env`

### 8.3 日志与监控

1. Laravel 日志使用 `Log` facade，级别标准（debug/info/warning/error）
2. 生产环境日志写入文件或外部服务，不输出到浏览器
3. API 返回的错误不暴露内部细节
4. 提供 `/api/health` 健康检查接口

---

## 9. 提交与协作约束

### 9.1 提交信息

```
<type>: <简短描述>
```

类型：`feat` / `fix` / `docs` / `style` / `refactor` / `test` / `chore`

### 9.2 协作要求

1. 变更前先明确边界和影响面
2. 新增能力前先确认不影响已有功能
3. 文档随项目演进同步更新
4. 架构变更必须先更新 `docs/` 下的方案文档再写代码

---

## 10. Laravel 特定约束

### 10.1 Controller 不能有业务逻辑

```
✅ Controller → Action/Service → Model
❌ Controller → 直接操作 Model + 写业务判断
```

### 10.2 使用 API Resource 做数据转换

所有返回给前端的 JSON 都必须经过 Resource 层转换，不直接 `return $model`。

### 10.3 迁移文件不可逆时必须标注

如果某个 migration 的 `down()` 无法完全回滚，必须在文件头部注释说明原因。

---

## 11. Next.js 特定约束

### 11.1 Server Component 优先

默认使用 Server Component，仅在需要：
- `useState` / `useEffect`
- 事件处理（onClick、onChange）
- 浏览器 API（localStorage、fetch）

时加 `'use client'`。

### 11.2 图片优化

- 商品图片使用 `next/image` 组件
- 3D 展示图使用 CDN 加载
- 不把原始大图直接塞到 `<img>` 标签

### 11.3 SEO

- 每个页面必须有 `<title>` 和 `<meta name="description">`
- 商品详情页必须有 Open Graph 标签
- 使用 Next.js `generateMetadata` API

---

## 12. 当前结论

所有 HOLP 项目代码默认遵守以下基线：

1. 模块化设计
2. 单一职责
3. 可读性优先
4. 目录整洁
5. 代码规范文档化
6. API 结构化（RESTful + JSON + Resource 层）
7. 前后端边界清晰
8. 测试可验证
9. 配置与日志规范
10. 协作规则清晰
