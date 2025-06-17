# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

Use MCP documentation from https://modelcontextprotocol.io/introduction to check implementation details.

## Project Overview

This is a PHP library that implements the Model Context Protocol (MCP) server specification. MCP enables seamless integration between LLM applications and external data sources/tools through a standardized JSON-RPC 2.0 protocol over HTTP with Server-Sent Events (SSE) support.

## Development Commands

### Testing
- `docker run --rm -i -v $PWD:/usr/src/app thecodingmachine/php:8.1-v4-cli ./vendor/bin/phpunit tests/` - Run all tests
- `docker run --rm -i -v $PWD:/usr/src/app thecodingmachine/php:8.1-v4-cli ./vendor/bin/phpunit tests/McpServerTest.php` - Run specific test file
- `docker run --rm -i -v $PWD:/usr/src/app thecodingmachine/php:8.1-v4-cli ./vendor/bin/phpunit tests/Auth/McpOAuthTest.php` - Run OAuth tests
- `docker run --rm -i -v $PWD:/usr/src/app thecodingmachine/php:8.1-v4-cli ./vendor/bin/phpunit --filter testMethodName tests/` - Run specific test method

### Static Analysis
- `docker run --rm -i -v $PWD:/usr/src/app thecodingmachine/php:8.1-v4-cli ./vendor/bin/phpstan analyse` - Run PHPStan static analysis (level 5)
- `docker run --rm -i -v $PWD:/usr/src/app thecodingmachine/php:8.1-v4-cli ./vendor/bin/phpstan analyse src` - Analyze only src directory

### Dependency Management
- `docker run --rm -i -v $PWD:/usr/src/app thecodingmachine/php:8.1-v4-cli composer install` - Install dependencies
- `docker run --rm -i -v $PWD:/usr/src/app thecodingmachine/php:8.1-v4-cli composer update` - Update dependencies
- `docker run --rm -i -v $PWD:/usr/src/app thecodingmachine/php:8.1-v4-cli composer dump-autoload` - Regenerate autoloader

