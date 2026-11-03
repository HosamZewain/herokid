import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

export const root = dirname(fileURLToPath(import.meta.url));
export const version = '0.166.0';
export const networkProfile = 'private-proxy-v1';
export const subscriptionProxy = 'http://herokid-autopilot-egress:3128';
export const disabledFeatures = [
  'shell_tool', 'unified_exec', 'unified_exec_tty', 'shell_snapshot', 'image_generation',
  'view_image', 'apps', 'hooks', 'multi_agent', 'multi_agent_v2', 'code_mode', 'code_mode_host',
  'code_mode_only', 'code_mode_tool_search', 'sleep_tool', 'tool_suggest', 'skill_mcp_dependency_install',
  'remote_plugin', 'computer_use', 'browser_use', 'browser_use_external', 'browser_use_full_cdp_access',
  'in_app_browser', 'browser_annotation_api', 'enable_mcp_apps', 'request_permissions_tool',
];

export const configuration = Object.freeze({
  forced_login_method: 'chatgpt', model_provider: 'openai', web_search: 'disabled',
  cli_auth_credentials_store: 'file', history: {persistence: 'none'},
  approval_policy: 'never', sandbox_mode: 'read-only',
  features: Object.fromEntries(disabledFeatures.map(name => [name, false])),
  shell_environment_policy: { inherit: 'none' },
});

export function isolatedEnvironment(authDirectory, temporaryDirectory) {
  return {
    PATH: `${dirname(process.execPath)}:/usr/bin:/bin`,
    CODEX_HOME: authDirectory, TMPDIR: temporaryDirectory, RUST_LOG: 'error',
    // Fixed trusted egress; orders and the host environment cannot select it.
    HTTPS_PROXY: subscriptionProxy, HTTP_PROXY: subscriptionProxy,
    NO_PROXY: '127.0.0.1,localhost',
  };
}

export function sdkOptions(environment) {
  return { codexPathOverride: resolve(root, 'codex-entry.mjs'), env: environment, config: configuration };
}

export function threadOptions(workingDirectory) {
  return { workingDirectory, sandboxMode: 'read-only', approvalPolicy: 'never', skipGitRepoCheck: true,
    webSearchMode: 'disabled', networkAccessEnabled: false, threadSource: 'herokid-shadow' };
}
