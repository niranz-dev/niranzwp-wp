<?php
/**
 * Admin screens: Configuration, Abilities, Troubleshoot.
 *
 * @package NiranzWP
 */

declare( strict_types = 1 );

namespace NiranzWP;

defined( 'ABSPATH' ) || exit;

final class Admin {

	private const SLUG  = 'niranzwp';
	private const NONCE = 'niranzwp_save';

	public static function init(): void {
		add_action( 'admin_menu', [ self::class, 'menu' ] );
		add_action( 'admin_post_niranzwp_save', [ self::class, 'handle_save' ] );
		add_action( 'admin_bar_menu', [ self::class, 'admin_bar' ], 100 );
	}

	public static function menu(): void {
		add_menu_page( 'NiranzWP', 'NiranzWP', CAPABILITY, self::SLUG, [ self::class, 'render_configuration' ], 'dashicons-rest-api', 76 );
		add_submenu_page( self::SLUG, __( 'Configuration', 'niranzwp' ), __( 'Configuration', 'niranzwp' ), CAPABILITY, self::SLUG, [ self::class, 'render_configuration' ] );
		add_submenu_page( self::SLUG, __( 'Connections', 'niranzwp' ), __( 'Connections', 'niranzwp' ), CAPABILITY, self::SLUG . '-connections', [ Connections::class, 'render' ] );
		add_submenu_page( self::SLUG, __( 'Abilities', 'niranzwp' ), __( 'Abilities', 'niranzwp' ), CAPABILITY, self::SLUG . '-abilities', [ self::class, 'render_abilities' ] );
		add_submenu_page( self::SLUG, __( 'Troubleshoot', 'niranzwp' ), __( 'Troubleshoot', 'niranzwp' ), CAPABILITY, self::SLUG . '-troubleshoot', [ self::class, 'render_troubleshoot' ] );
	}

	public static function admin_bar( \WP_Admin_Bar $bar ): void {
		if ( ! Settings::active() || ! current_user_can( CAPABILITY ) ) {
			return;
		}
		$bar->add_node( [
			'id'    => 'niranzwp-on',
			'title' => '<span style="background:#2271b1;color:#fff;padding:0 8px;border-radius:3px;font-weight:600">NiranzWP ON</span>',
			'href'  => admin_url( 'admin.php?page=' . self::SLUG ),
		] );
	}

	public static function handle_save(): void {
		if ( ! current_user_can( CAPABILITY ) || ! check_admin_referer( self::NONCE ) ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'niranzwp' ), '', [ 'response' => 403 ] );
		}

		$enabled = isset( $_POST['enabled'] );
		Settings::set_enabled( $enabled );
		Settings::set_files( isset( $_POST['files'] ) );
		Settings::set_runtime( isset( $_POST['runtime'] ) );
		if ( $enabled ) {
			Settings::remember_domain();
		}

		wp_safe_redirect( add_query_arg( 'updated', '1', admin_url( 'admin.php?page=' . self::SLUG ) ) );
		exit;
	}

	private static function styles(): void {
		?>
		<style>
			.nzwp-wrap{max-width:900px}
			.nzwp-head{display:flex;align-items:baseline;gap:12px;margin:0 0 4px}
			.nzwp-head h1{margin:0;font-size:23px}
			.nzwp-sub{color:#646970;margin:0 0 20px}
			.nzwp-card{background:#fff;border:1px solid #dcdcde;border-radius:6px;padding:20px 24px;margin:0 0 16px}
			.nzwp-card h2{display:flex;align-items:center;gap:10px;margin:0 0 12px;font-size:15px}
			.nzwp-num{display:inline-flex;align-items:center;justify-content:center;width:24px;height:24px;border-radius:50%;background:#1d2327;color:#fff;font-size:12px;font-weight:600;flex:none}
			.nzwp-badge{display:inline-block;padding:2px 9px;border-radius:11px;font-size:12px;font-weight:600;line-height:18px}
			.nzwp-on{background:#d5f5e3;color:#0a5c36}
			.nzwp-off{background:#f0f0f1;color:#646970}
			.nzwp-warn{background:#fcf0e0;color:#8a5700}
			.nzwp-code{background:#1d2327;color:#f0f0f1;padding:14px 16px;border-radius:5px;overflow:auto;font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:13px;line-height:1.7;margin:10px 0 6px}
			.nzwp-desc{color:#646970;margin:4px 0 0;font-size:13px}
			.nzwp-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:12px;margin-top:12px}
			.nzwp-stat{border:1px solid #dcdcde;border-radius:5px;padding:12px 14px}
			.nzwp-stat b{display:block;font-size:20px;line-height:1.3}
			.nzwp-stat span{color:#646970;font-size:12px}
			.nzwp-tabs{display:flex;flex-wrap:wrap;gap:6px;margin:12px 0 0}
			.nzwp-tab{padding:5px 13px;border:1px solid #dcdcde;border-radius:14px;background:#fff;cursor:pointer;font-size:13px;line-height:1.5}
			.nzwp-tab[aria-selected="true"]{background:#2271b1;border-color:#2271b1;color:#fff;font-weight:600}
			.nzwp-pane{display:none}
			.nzwp-pane.is-on{display:block}
			.nzwp-copywrap{position:relative}
			.nzwp-copy{position:absolute;top:8px;right:8px;background:#3c434a;color:#f0f0f1;border:0;border-radius:4px;padding:4px 10px;font-size:12px;cursor:pointer}
			.nzwp-copy:hover{background:#50575e}
			.nzwp-copy.done{background:#0a5c36}
		</style>
		<?php
	}

	public static function header( string $title ): void {
		self::styles();
		echo '<div class="wrap nzwp-wrap">';
		echo '<div class="nzwp-head"><h1>' . esc_html( $title ) . '</h1>';
		echo '<span class="nzwp-badge ' . ( Settings::active() ? 'nzwp-on">Abilities on' : 'nzwp-off">Abilities off' ) . '</span></div>';
		echo '<p class="nzwp-sub">NiranzWP ' . esc_html( VERSION ) . ' &middot; <a href="https://niranz.dev" target="_blank" rel="noopener">built by Niranjan</a></p>';
	}

	public static function render_configuration(): void {
		self::header( __( 'NiranzWP', 'niranzwp' ) );

		if ( isset( $_GET['updated'] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Settings saved.', 'niranzwp' ) . '</p></div>';
		}
		if ( ! Settings::domain_matches() ) {
			echo '<div class="notice notice-warning"><p>' . esc_html__( 'Abilities were enabled for a different domain, so they are inactive here. Save again to enable them on this domain.', 'niranzwp' ) . '</p></div>';
		}

		$enabled  = Settings::enabled();
		$host     = Settings::current_domain();
		$mcp_up   = class_exists( '\WP\MCP\Core\McpAdapter' );
		$count    = count( self::own_abilities() );
		?>

		<div class="nzwp-card">
			<h2><span class="nzwp-num">1</span><?php esc_html_e( 'Enable abilities', 'niranzwp' ); ?></h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="niranzwp_save">
				<?php wp_nonce_field( self::NONCE ); ?>
				<p>
					<input type="checkbox" name="enabled" id="nzwp-enabled" value="1" autocomplete="off" style="position:relative;z-index:1" <?php checked( $enabled ); ?>>
					<label for="nzwp-enabled" style="cursor:pointer;font-weight:600">
						<?php esc_html_e( 'Allow connected clients to use this site\'s abilities', 'niranzwp' ); ?>
					</label>
				</p>
				<p class="nzwp-desc">
					<?php esc_html_e( 'Abilities are admin-only, and the write ones preview before they change anything. They are still real access to this site, so leave this off unless something is connecting.', 'niranzwp' ); ?>
				</p>
				<p class="nzwp-desc">
					<?php esc_html_e( 'Access is locked to this domain. Restoring the database elsewhere will not carry it over.', 'niranzwp' ); ?>
				</p>

				<p style="margin-top:16px">
					<input type="checkbox" name="files" id="nzwp-files" value="1" autocomplete="off" style="position:relative;z-index:1" <?php checked( Settings::files_enabled() ); ?>>
					<label for="nzwp-files" style="cursor:pointer;font-weight:600">
						<?php esc_html_e( 'Also allow filesystem abilities', 'niranzwp' ); ?>
					</label>
				</p>
				<p class="nzwp-desc">
					<?php esc_html_e( 'Read, write and delete files inside the WordPress install. Writes and deletes preview first; wp-config.php and the core directories are always refused. Leave this off on a production site.', 'niranzwp' ); ?>
				</p>

				<p style="margin-top:16px">
					<input type="checkbox" name="runtime" id="nzwp-runtime" value="1" autocomplete="off" style="position:relative;z-index:1" <?php checked( Settings::runtime_enabled() ); ?>>
					<label for="nzwp-runtime" style="cursor:pointer;font-weight:600">
						<?php esc_html_e( 'Also allow PHP evaluation and WP-CLI', 'niranzwp' ); ?>
						<span class="nzwp-badge nzwp-warn"><?php esc_html_e( 'full control', 'niranzwp' ); ?></span>
					</label>
				</p>
				<p class="nzwp-desc">
					<?php esc_html_e( 'Lets a connected client evaluate PHP inside this site and run WP-CLI commands against it. Whoever holds the credential can do anything WordPress can do. Development and staging only.', 'niranzwp' ); ?>
				</p>
				<?php submit_button( __( 'Save settings', 'niranzwp' ), 'primary', 'submit', false ); ?>
			</form>

			<div class="nzwp-grid">
				<div class="nzwp-stat"><b><?php echo (int) $count; ?></b><span><?php esc_html_e( 'abilities registered', 'niranzwp' ); ?></span></div>
				<div class="nzwp-stat"><b><?php echo $enabled ? '&#10003;' : '&mdash;'; ?></b><span><?php esc_html_e( 'clients allowed', 'niranzwp' ); ?></span></div>
				<div class="nzwp-stat"><b><?php echo $mcp_up ? '&#10003;' : '&mdash;'; ?></b><span><?php esc_html_e( 'MCP adapter loaded', 'niranzwp' ); ?></span></div>
				<div class="nzwp-stat"><b><?php echo count( Connections::all() ); ?></b><span><a href="<?php echo esc_url( admin_url( 'admin.php?page=niranzwp-connections' ) ); ?>"><?php esc_html_e( 'connected clients', 'niranzwp' ); ?></a></span></div>
			</div>
		</div>

		<div class="nzwp-card">
			<h2><span class="nzwp-num">2</span><?php esc_html_e( 'Connect a client', 'niranzwp' ); ?></h2>
			<p class="nzwp-desc"><?php esc_html_e( 'Pick what you use. Copy the block, paste it where it says.', 'niranzwp' ); ?></p>

			<div class="nzwp-tabs" role="tablist">
				<?php foreach ( self::clients( $host ) as $i => $c ) : ?>
					<button type="button" class="nzwp-tab" role="tab"
						aria-selected="<?php echo 0 === $i ? 'true' : 'false'; ?>"
						data-pane="nzwp-pane-<?php echo (int) $i; ?>"><?php echo esc_html( $c['label'] ); ?></button>
				<?php endforeach; ?>
			</div>

			<?php foreach ( self::clients( $host ) as $i => $c ) : ?>
				<div class="nzwp-pane <?php echo 0 === $i ? 'is-on' : ''; ?>" id="nzwp-pane-<?php echo (int) $i; ?>">
					<p class="nzwp-desc" style="margin-top:14px"><?php echo esc_html( $c['note'] ); ?></p>
					<div class="nzwp-copywrap">
						<button type="button" class="nzwp-copy"><?php esc_html_e( 'Copy', 'niranzwp' ); ?></button>
						<div class="nzwp-code"><?php echo esc_html( $c['code'] ); ?></div>
					</div>
					<?php if ( ! empty( $c['where'] ) ) : ?>
						<p class="nzwp-desc"><strong><?php esc_html_e( 'Paste into:', 'niranzwp' ); ?></strong> <code><?php echo esc_html( $c['where'] ); ?></code></p>
					<?php endif; ?>
				</div>
			<?php endforeach; ?>
		</div>

		<script>
		(function () {
			var wrap = document.querySelectorAll('.nzwp-tab');
			wrap.forEach(function (tab) {
				tab.addEventListener('click', function () {
					wrap.forEach(function (t) {
						t.setAttribute('aria-selected', String(t === tab));
					});
					document.querySelectorAll('.nzwp-pane').forEach(function (p) {
						p.classList.toggle('is-on', p.id === tab.dataset.pane);
					});
				});
			});
			document.querySelectorAll('.nzwp-copy').forEach(function (btn) {
				btn.addEventListener('click', function () {
					var text = btn.parentNode.querySelector('.nzwp-code').innerText;
					navigator.clipboard.writeText(text).then(function () {
						var was = btn.textContent;
						btn.textContent = 'Copied';
						btn.classList.add('done');
						setTimeout(function () { btn.textContent = was; btn.classList.remove('done'); }, 1600);
					});
				});
			});
		}());
		</script>
		</div>
		<?php
	}

	/**
	 * Connection snippets per client. The CLI needs no configuration file at
	 * all, so it comes first; the MCP clients each want their own JSON in
	 * their own location.
	 *
	 * @return array<int,array{label:string,note:string,code:string,where:string}>
	 */
	private static function clients( string $host ): array {
		$endpoint = Mcp::endpoint();
		$slug     = 'niranzwp-' . str_replace( '.', '-', $host );

		return [
			[
				'label' => 'NiranzWP CLI',
				'note'  => __( 'No config file. Install it, approve once in the browser, done.', 'niranzwp' ),
				'code'  => "npm install -g niranzwp\nniranzwp auth login {$host}\n\nniranzwp seo audit\nniranzwp geo check",
				'where' => '',
			],
			[
				'label' => 'Claude Code',
				'note'  => __( 'Run this in your terminal.', 'niranzwp' ),
				'code'  => "claude mcp add --transport http {$slug} \\\n  {$endpoint}",
				'where' => '',
			],
			[
				'label' => 'Claude Desktop',
				'note'  => __( 'Add this inside the mcpServers block.', 'niranzwp' ),
				'code'  => "{\n  \"mcpServers\": {\n    \"{$slug}\": {\n      \"type\": \"http\",\n      \"url\": \"{$endpoint}\"\n    }\n  }\n}",
				'where' => '~/Library/Application Support/Claude/claude_desktop_config.json',
			],
			[
				'label' => 'Cursor',
				'note'  => __( 'Add this inside the mcpServers block.', 'niranzwp' ),
				'code'  => "{\n  \"mcpServers\": {\n    \"{$slug}\": {\n      \"url\": \"{$endpoint}\"\n    }\n  }\n}",
				'where' => '~/.cursor/mcp.json',
			],
			[
				'label' => 'Codex',
				'note'  => __( 'Add this to your Codex config.', 'niranzwp' ),
				'code'  => "[mcp_servers.{$slug}]\nurl = \"{$endpoint}\"",
				'where' => '~/.codex/config.toml',
			],
		];
	}

	/** @return array<int,\WP_Ability> */
	private static function own_abilities(): array {
		if ( ! function_exists( 'wp_get_abilities' ) ) {
			return [];
		}
		$out = [];
		foreach ( wp_get_abilities() as $key => $a ) {
			$name = is_object( $a ) && method_exists( $a, 'get_name' ) ? $a->get_name() : (string) $key;
			if ( str_starts_with( $name, 'niranzwp/' ) ) {
				$out[ $name ] = $a;
			}
		}
		ksort( $out );
		return $out;
	}

	public static function render_abilities(): void {
		self::header( __( 'Abilities', 'niranzwp' ) );

		$mine = self::own_abilities();
		echo '<div class="nzwp-card">';
		echo '<p class="nzwp-desc">' . esc_html( sprintf(
			/* translators: %d: number of abilities */
			__( '%d abilities registered by NiranzWP. Read-only ones are safe to run at any time; write ones preview before changing anything.', 'niranzwp' ),
			count( $mine )
		) ) . '</p>';

		echo '<table class="widefat striped" style="margin-top:12px"><thead><tr>';
		echo '<th>' . esc_html__( 'Ability', 'niranzwp' ) . '</th><th style="width:90px">' . esc_html__( 'Type', 'niranzwp' ) . '</th><th>' . esc_html__( 'What it does', 'niranzwp' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $mine as $name => $a ) {
			$meta     = is_object( $a ) && method_exists( $a, 'get_meta' ) ? (array) $a->get_meta() : [];
			$readonly = ! empty( $meta['annotations']['readonly'] );
			$desc     = is_object( $a ) && method_exists( $a, 'get_description' ) ? $a->get_description() : '';

			printf(
				'<tr><td><code>%s</code></td><td><span class="nzwp-badge %s">%s</span></td><td>%s</td></tr>',
				esc_html( $name ),
				$readonly ? 'nzwp-on' : 'nzwp-warn',
				$readonly ? esc_html__( 'read', 'niranzwp' ) : esc_html__( 'write', 'niranzwp' ),
				esc_html( $desc )
			);
		}

		echo '</tbody></table></div></div>';
	}

	public static function render_troubleshoot(): void {
		self::header( __( 'Troubleshoot', 'niranzwp' ) );

		echo '<div class="nzwp-card"><table class="widefat striped"><thead><tr>';
		echo '<th style="width:70px">' . esc_html__( 'Status', 'niranzwp' ) . '</th><th style="width:230px">' . esc_html__( 'Check', 'niranzwp' ) . '</th><th>' . esc_html__( 'Detail', 'niranzwp' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( self::checks() as $check ) {
			$icon = 'pass' === $check['status'] ? '&#9989;' : ( 'warn' === $check['status'] ? '&#9888;&#65039;' : '&#10060;' );
			printf(
				'<tr><td>%s</td><td><strong>%s</strong></td><td>%s</td></tr>',
				$icon,
				esc_html( $check['label'] ),
				esc_html( $check['detail'] )
			);
		}

		echo '</tbody></table></div></div>';
	}

	/** @return array<int,array{status:string,label:string,detail:string}> */
	private static function checks(): array {
		$wp    = get_bloginfo( 'version' );
		$https = str_starts_with( home_url(), 'https://' );
		$local = 'local' === wp_get_environment_type();
		$env   = wp_get_environment_type();

		$out = [
			[
				'status' => version_compare( $wp, '6.9', '>=' ) ? 'pass' : 'fail',
				'label'  => 'WordPress 6.9+',
				'detail' => 'Running ' . $wp . '. The Abilities API needs 6.9.',
			],
			[
				'status' => version_compare( PHP_VERSION, '8.0', '>=' ) ? 'pass' : 'fail',
				'label'  => 'PHP 8.0+',
				'detail' => 'Running ' . PHP_VERSION . '.',
			],
			[
				'status' => function_exists( 'wp_register_ability' ) ? 'pass' : 'fail',
				'label'  => 'Abilities API',
				'detail' => function_exists( 'wp_register_ability' ) ? 'Available.' : 'Missing - abilities cannot register.',
			],
			[
				'status' => count( self::own_abilities() ) > 0 ? 'pass' : 'fail',
				'label'  => 'Abilities registered',
				'detail' => count( self::own_abilities() ) . ' registered by NiranzWP.',
			],
			[
				'status' => class_exists( '\WP\MCP\Core\McpAdapter' ) ? 'pass' : 'warn',
				'label'  => 'MCP adapter',
				'detail' => class_exists( '\WP\MCP\Core\McpAdapter' )
					? 'Loaded. Endpoint: ' . Mcp::endpoint()
					: 'Not loaded. Install from the release ZIP, which bundles vendor dependencies.',
			],
			[
				'status' => ( $https || $local ) ? 'pass' : 'fail',
				'label'  => 'HTTPS for credentials',
				'detail' => $https ? 'Site is served over HTTPS.' : ( $local ? 'Not HTTPS, but the environment is marked local.' : 'WordPress disables Application Passwords without HTTPS.' ),
			],
			[
				'status' => wp_is_application_passwords_available() ? 'pass' : 'fail',
				'label'  => 'Application Passwords',
				'detail' => wp_is_application_passwords_available() ? 'Available.' : 'Disabled - a security plugin may be blocking them.',
			],
			[
				'status' => Settings::active() ? 'pass' : 'warn',
				'label'  => 'Abilities enabled',
				'detail' => Settings::active() ? 'On for ' . Settings::current_domain() . '.' : 'Off. Enable them under Configuration.',
			],
			[
				'status' => 'production' === $env ? 'warn' : 'pass',
				'label'  => 'Environment',
				'detail' => 'Reported as "' . $env . '".' . ( 'production' === $env ? ' Prefer staging for agent work.' : '' ),
			],
		];

		return $out;
	}
}
