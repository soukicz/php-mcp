<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use Soukicz\Llm\Message\LLMMessageContents;
use Soukicz\Llm\Message\LLMMessageText;
use Soukicz\Mcp\Auth\McpAuthContext;
use Soukicz\Mcp\Auth\McpUser;
use Soukicz\Mcp\McpServer;
use Soukicz\Mcp\Model\McpCapabilities;
use Soukicz\Mcp\Model\McpServerInfo;
use Soukicz\Mcp\Tool\McpCallbackToolDefinition;

// Example: Tool that uses user context
$userProfileTool = new McpCallbackToolDefinition(
    'get_user_profile',
    'Get current user profile information',
    [
        'type' => 'object',
        'properties' => [],
        'required' => []
    ],
    function (array $arguments, ?McpAuthContext $authContext = null) {
        
        if (!$authContext instanceof McpAuthContext) {
            return new LLMMessageContents([new LLMMessageText('No user authentication available')]);
        }
        
        $user = $authContext->user;
        if ($user === null) {
            return new LLMMessageContents([new LLMMessageText('User information not available')]);
        }
        
        $profile = [
            'id' => $user->id,
            'email' => $user->email,
            'name' => $user->name,
            'scopes' => $user->scopes,
        ];
        
        return new LLMMessageContents([new LLMMessageText('User Profile: ' . json_encode($profile, JSON_PRETTY_PRINT))]);
    }
);

// Example: Tool that checks user permissions
$permissionTool = new McpCallbackToolDefinition(
    'check_permission',
    'Check if current user has a specific permission',
    [
        'type' => 'object',
        'properties' => [
            'permission' => ['type' => 'string', 'description' => 'Permission to check']
        ],
        'required' => ['permission']
    ],
    function (array $arguments, ?McpAuthContext $authContext = null) {
        $permission = $arguments['permission'] ?? '';
        
        if (!$authContext instanceof McpAuthContext) {
            return new LLMMessageContents([new LLMMessageText('Authentication required to check permissions')]);
        }
        
        $user = $authContext->user;
        if ($user === null) {
            return new LLMMessageContents([new LLMMessageText('User information not available')]);
        }
        
        $hasPermission = $user->hasScope($permission);
        $message = $hasPermission 
            ? "User {$user->id} HAS permission: {$permission}"
            : "User {$user->id} does NOT have permission: {$permission}";
            
        return new LLMMessageContents([new LLMMessageText($message)]);
    }
);

// Example: Tool that works with or without auth context (backward compatible)
$flexibleTool = new McpCallbackToolDefinition(
    'get_timestamp',
    'Get current timestamp, with user info if authenticated',
    [
        'type' => 'object',
        'properties' => [],
        'required' => []
    ],
    function (array $arguments, ?McpAuthContext $authContext = null) {
        $timestamp = date('Y-m-d H:i:s');
        
        if ($authContext instanceof McpAuthContext && $authContext->user !== null) {
            $message = "Current time: {$timestamp} (requested by user: {$authContext->user->id})";
        } else {
            $message = "Current time: {$timestamp} (anonymous request)";
        }
        
        return new LLMMessageContents([new LLMMessageText($message)]);
    }
);

// Create server with user-aware tools
$server = new McpServer(
    serverInfo: new McpServerInfo(
        name: 'User Context Example Server',
        version: '1.0.0'
    ),
    capabilities: new McpCapabilities(
        tools: true
    ),
    tools: [$userProfileTool, $permissionTool, $flexibleTool]
);

echo "Example server with user-context-aware tools created.\n";
echo "Tools available:\n";
foreach ($server->getTools() as $tool) {
    echo "- {$tool->getName()}: {$tool->getDescription()}\n";
}

// Example of how to call tools with user context
$testUser = new McpUser(
    id: 'user123',
    scopes: ['read', 'profile'],
    email: 'test@example.com',
    name: 'Test User'
);

$testAuthContext = new McpAuthContext(
    token: new \Soukicz\Mcp\Auth\McpOAuthToken('test-token', 'Bearer'),
    user: $testUser
);

echo "\n--- Testing tool calls with user context ---\n";

// Call tools with user context
$result1 = $server->callTool('get_user_profile', [], $testAuthContext);
echo "User Profile Result: " . $result1->getMessages()[0]->getText() . "\n\n";

$result2 = $server->callTool('check_permission', ['permission' => 'read'], $testAuthContext);
echo "Permission Check Result: " . $result2->getMessages()[0]->getText() . "\n\n";

$result3 = $server->callTool('get_timestamp', [], $testAuthContext);
echo "Timestamp Result: " . $result3->getMessages()[0]->getText() . "\n\n";

echo "--- Testing tool calls without user context ---\n";

$result4 = $server->callTool('get_timestamp', []);
echo "Anonymous Timestamp Result: " . $result4->getMessages()[0]->getText() . "\n";