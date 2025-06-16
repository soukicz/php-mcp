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
- `docker run --rm -i -v $PWD:/usr/src/app thecodingmachine/php:8.1-v4-cli ./composer install` - Install dependencies
- `docker run --rm -i -v $PWD:/usr/src/app thecodingmachine/php:8.1-v4-cli ./composer update` - Update dependencies
- `docker run --rm -i -v $PWD:/usr/src/app thecodingmachine/php:8.1-v4-cli ./composer dump-autoload` - Regenerate autoloader

### Development Server
- `docker run --rm -i -p 8080:8080 -v $PWD:/usr/src/app thecodingmachine/php:8.1-v4-cli php -S localhost:8080 examples/mcp-server-example.php` - Start development server

## Architecture Overview

### Core Components

**McpServer** (`src/McpServer.php`): Main server implementation that manages tools, prompts, resources, and sessions. Implements `McpServerInterface`.

**McpHttpTransport** (`src/Transport/McpHttpTransport.php`): HTTP transport layer handling JSON-RPC requests and SSE streaming. Manages authentication middleware integration.

**Session Management**: Stateful sessions with unique IDs enabling connection resumability and protocol negotiation.

### Key Architectural Patterns

**Protocol Layer** (`src/Protocol/`): JSON-RPC 2.0 implementation with request/response/error structures following MCP specification.

**Model Layer** (`src/Model/`): Value objects representing MCP entities (capabilities, prompts, resources, sessions) with immutable design.

**Transport Layer** (`src/Transport/`): HTTP/SSE transport with event store for message replay and connection recovery.

**Authentication Layer** (`src/Auth/`): Provider-independent authentication via `McpOAuthServerInterface`. Support for any OAuth implementation (League, custom, external APIs).

### Session Lifecycle

1. Client calls `initialize` → Server creates `McpSession` with unique ID
2. Server returns session ID in response
3. Client uses session ID in subsequent requests: `POST /mcp?sessionId=abc123`
4. Client can open SSE stream: `GET /mcp?sessionId=abc123`
5. Client calls `initialized` to complete handshake
6. Normal MCP communication proceeds

### Event Store Pattern

The transport layer uses `McpEventStore` (with `McpMemoryEventStore` implementation) for:
- Message replay for session resumption
- Connection recovery with Last-Event-ID support
- SSE event persistence and ordering

### Authentication Integration

Authentication middleware (`McpAuthMiddlewareInterface`) integrates with transport:
- Generic OAuth integration via `McpGenericOAuthMiddleware`
- Provider-independent `McpOAuthServerInterface` for any OAuth implementation
- Support for League OAuth2, custom JWT, external APIs, or database validation
- Four simple methods to implement: validate, authorize, token, isPublic
- Applications control all storage and business logic
- Custom authentication via interface implementation

## Dependencies

- `soukicz/llm`: Core LLM integration library (dev-master)
- Built on PSR-7 HTTP messages via GuzzleHttp
- Uses standard PHP-FIG interfaces for HTTP handling

### Suggested Dependencies (Optional)

- `league/oauth2-server`: For OAuth 2.1 server implementation (see `examples/LeagueOAuthServerAdapter.php`)
- `firebase/php-jwt`: For custom JWT token validation

## Testing Patterns

Tests use PHPUnit with focused unit testing of core components. Test structure mirrors src directory organization. Mock authentication and transport layers for isolated testing.
