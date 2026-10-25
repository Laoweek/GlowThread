[English](SECURITY.md) | 简体中文

# 安全政策

## 报告漏洞

请不要在公开的 issue、pull request 或讨论中报告安全问题。

请通过本仓库的 **Security** 标签页私下报告：选择 **Report a vulnerability**。只有维护者能看到报告。

请写明：

- 攻击者能做什么，需要哪些权限或配置
- 复现步骤，最好在一个全新的维基上
- MediaWiki 版本、GlowThread 版本或提交，以及皮肤
- 相关的设置，来自 `LocalSettings.php` 或 Special:GlowThread/settings

## 适用范围

适用：

- `includes/`、`maintenance/`、`sql/`、`resources/` 和 `client/` 中的扩展代码
- 文档推荐的部署方式，例如 [docs/zh-CN/caching.md](docs/zh-CN/caching.md) 中的缓存规则

不适用：

- `dev/` 中的开发环境，它只用于本地开发
- MediaWiki 本身或其他扩展的问题：请报告给它们的维护者，MediaWiki 的问题请按 [Reporting security bugs](https://www.mediawiki.org/wiki/Reporting_security_bugs) 报告

## 支持的版本

GlowThread 还没有发布任何版本。安全修复会合并到 `main` 分支，请更新到最新的 `main` 以获得修复。有了正式版本之后，每个修复也会进入下一个版本。

## 安全地运行

[docs/zh-CN/configuration.md](docs/zh-CN/configuration.md) 说明开放维基需要注意的权限、限制和设置，[docs/zh-CN/caching.md](docs/zh-CN/caching.md) 说明如何在前面加一层 CDN。
