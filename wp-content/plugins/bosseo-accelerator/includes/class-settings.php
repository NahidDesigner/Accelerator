<?php
/**
 * Settings and admin page for Bosseo Accelerator.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Bosseo_Accelerator_Settings {
	const OPTION_KEY = 'bosseo_accelerator_options';

	public function init() {
		add_action( 'admin_menu', [ $this, 'add_menu' ] );
		add_action( 'admin_init', [ $this, 'register_settings' ] );
	}

	public function get_default_options(): array {
		return [
			'enabled' => 1,
			'optimize_logged_in' => 0,
			'delay_third_party' => 1,
			'suppress_notices' => 0,
			'hero_mode' => 'smart', // smart | off
			'critical_css' => '',
			'minify_critical_css' => 0,
			'excluded_ids' => '',
			'allowlisted_scripts' => implode("\n", [ 'jquery', 'jquery-core', 'jquery-migrate', 'elementor-frontend' ]),
			'allowlisted_styles' => implode("\n", [ 'elementor-frontend', 'elementor-icons' ]),
			'third_party_patterns' => implode("\n", [
				'googletagmanager.com',
				'google-analytics.com',
				'analytics.google.com',
				'gtag/js',
				'connect.facebook.net',
				'facebook.net',
				'hotjar.com',
				'clarity.ms',
				'optimize.google.com',
				'doubleclick.net',
			]),
		];
	}

	public function get_options(): array {
		$saved = get_option( self::OPTION_KEY, [] );
		$defaults = $this->get_default_options();
		if ( ! is_array( $saved ) ) {
			$saved = [];
		}
		return array_merge( $defaults, $saved );
	}

	public function parse_list( string $text ): array {
		$lines = preg_split( '/\r?\n/', $text );
		$out = [];
		foreach ( $lines as $line ) {
			$trim = trim( $line );
			if ( $trim !== '' ) {
				$out[] = $trim;
			}
		}
		return $out;
	}

	public function parse_id_list( string $text ): array {
		$ids = [];
		$text = str_replace( [',', ';', '\t'], "\n", $text );
		foreach ( $this->parse_list( $text ) as $id ) {
			$int = (int) $id;
			if ( $int > 0 ) {
				$ids[] = $int;
			}
		}
		return $ids;
	}

	public function get_patterns_list(): array {
		$options = $this->get_options();
		return $this->parse_list( (string) ( $options['third_party_patterns'] ?? '' ) );
	}

	public function get_allowlisted_scripts(): array {
		$options = $this->get_options();
		return $this->parse_list( (string) ( $options['allowlisted_scripts'] ?? '' ) );
	}

	public function get_allowlisted_styles(): array {
		$options = $this->get_options();
		return $this->parse_list( (string) ( $options['allowlisted_styles'] ?? '' ) );
	}

	public function add_menu() {
		add_options_page(
			__( 'Bosseo Accelerator', 'bosseo-accelerator' ),
			__( 'Bosseo Accelerator', 'bosseo-accelerator' ),
			'manage_options',
			'bosseo-accelerator',
			[ $this, 'render_settings_page' ]
		);
	}

	public function register_settings() {
		register_setting( 'bosseo_accelerator', self::OPTION_KEY, [ $this, 'sanitize_options' ] );

		add_settings_section( 'bosseo_accel_main', __( 'Main Settings', 'bosseo-accelerator' ), function () {
			echo '<p>' . esc_html__( 'Optimize Elementor + Cloudflare sites for high PSI. Disable Cloudflare Rocket Loader to avoid conflicts.', 'bosseo-accelerator' ) . '</p>';
		}, 'bosseo-accelerator' );

		$this->add_field( 'enabled', __( 'Enable plugin', 'bosseo-accelerator' ), 'checkbox' );
		$this->add_field( 'optimize_logged_in', __( 'Optimize for logged-in users', 'bosseo-accelerator' ), 'checkbox' );
		$this->add_field( 'delay_third_party', __( 'Delay third-party scripts until interaction/idle', 'bosseo-accelerator' ), 'checkbox' );
		$this->add_field( 'suppress_notices', __( 'Suppress frontend notices/diagnostics', 'bosseo-accelerator' ), 'checkbox' );
		$this->add_field( 'hero_mode', __( 'Hero video mode', 'bosseo-accelerator' ), 'select', [ 'smart' => 'Smart', 'off' => 'Off' ] );
		$this->add_field( 'critical_css', __( 'Critical CSS (inlined)', 'bosseo-accelerator' ), 'textarea' );
		$this->add_field( 'minify_critical_css', __( 'Minify Critical CSS (simple)', 'bosseo-accelerator' ), 'checkbox' );
		$this->add_field( 'excluded_ids', __( 'Excluded post/page IDs (comma/line separated)', 'bosseo-accelerator' ), 'text' );
		$this->add_field( 'third_party_patterns', __( 'Third-party URL patterns (one per line)', 'bosseo-accelerator' ), 'textarea' );
		$this->add_field( 'allowlisted_scripts', __( 'Allowlisted script handles (one per line)', 'bosseo-accelerator' ), 'textarea' );
		$this->add_field( 'allowlisted_styles', __( 'Allowlisted style handles (one per line)', 'bosseo-accelerator' ), 'textarea' );
	}

	private function add_field( string $key, string $label, string $type, array $options = [] ) {
		add_settings_field(
			$key,
			esc_html( $label ),
			[ $this, 'render_field' ],
			'bosseo-accelerator',
			'bosseo_accel_main',
			[
				'key' => $key,
				'type' => $type,
				'options' => $options,
			]
		);
	}

	public function sanitize_options( $input ): array {
		$defaults = $this->get_default_options();
		$out = [];
		$out['enabled'] = empty( $input['enabled'] ) ? 0 : 1;
		$out['optimize_logged_in'] = empty( $input['optimize_logged_in'] ) ? 0 : 1;
		$out['delay_third_party'] = empty( $input['delay_third_party'] ) ? 0 : 1;
		$out['suppress_notices'] = empty( $input['suppress_notices'] ) ? 0 : 1;
		$out['hero_mode'] = in_array( $input['hero_mode'] ?? 'smart', [ 'smart', 'off' ], true ) ? $input['hero_mode'] : 'smart';
		$critical = (string) ( $input['critical_css'] ?? '' );
		$out['minify_critical_css'] = empty( $input['minify_critical_css'] ) ? 0 : 1;
		if ( $out['minify_critical_css'] ) {
			$critical = $this->minify_css_simple( $critical );
		}
		$out['critical_css'] = $critical;
		$out['excluded_ids'] = sanitize_text_field( (string) ( $input['excluded_ids'] ?? '' ) );
		$out['third_party_patterns'] = $this->sanitize_textarea_lines( (string) ( $input['third_party_patterns'] ?? $defaults['third_party_patterns'] ) );
		$out['allowlisted_scripts'] = $this->sanitize_textarea_lines( (string) ( $input['allowlisted_scripts'] ?? $defaults['allowlisted_scripts'] ) );
		$out['allowlisted_styles'] = $this->sanitize_textarea_lines( (string) ( $input['allowlisted_styles'] ?? $defaults['allowlisted_styles'] ) );
		return $out;
	}

	private function sanitize_textarea_lines( string $text ): string {
		$lines = preg_split( '/\r?\n/', $text );
		$clean = [];
		foreach ( $lines as $line ) {
			$line = trim( $line );
			if ( $line === '' ) {
				continue;
			}
			$clean[] = sanitize_text_field( $line );
		}
		return implode( "\n", $clean );
	}

	private function minify_css_simple( string $css ): string {
		// Remove comments.
		$css = preg_replace( '!/\*.*?\*/!s', '', $css );
		$css = preg_replace( '/\s+/', ' ', $css );
		$css = preg_replace( '/\s*([{};:,>])\s*/', '$1', $css );
		$css = trim( $css );
		return $css;
	}

	public function render_field( array $args ) {
		$key = $args['key'];
		$type = $args['type'];
		$options = $args['options'] ?? [];
		$vals = $this->get_options();
		$name = self::OPTION_KEY . '[' . $key . ']';
		$value = $vals[ $key ] ?? '';

		if ( $type === 'checkbox' ) {
			echo '<label><input type="checkbox" name="' . esc_attr( $name ) . '" value="1" ' . checked( (int) $value, 1, false ) . ' /> ' . esc_html__( 'Enable', 'bosseo-accelerator' ) . '</label>';
			return;
		}

		if ( $type === 'select' ) {
			echo '<select name="' . esc_attr( $name ) . '">';
			foreach ( $options as $opt_val => $opt_label ) {
				echo '<option value="' . esc_attr( $opt_val ) . '" ' . selected( (string) $value, (string) $opt_val, false ) . '>' . esc_html( $opt_label ) . '</option>';
			}
			echo '</select>';
			return;
		}

		if ( $type === 'textarea' ) {
			echo '<textarea style="width:100%;min-height:120px;" name="' . esc_attr( $name ) . '">' . esc_textarea( (string) $value ) . '</textarea>';
			return;
		}

		// text
		echo '<input type="text" style="width:100%;" name="' . esc_attr( $name ) . '" value="' . esc_attr( (string) $value ) . '" />';
	}

	public function render_settings_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		?>
		<div class="wrap">
			<h1><?php echo esc_html__( 'Bosseo Accelerator', 'bosseo-accelerator' ); ?></h1>
			<form method="post" action="options.php">
				<?php
				settings_fields( 'bosseo_accelerator' );
				do_settings_sections( 'bosseo-accelerator' );
				submit_button();
				?>
			</form>
			<p><em><?php echo esc_html__( 'Tip: With Cloudflare APO, disable Rocket Loader to avoid conflicts. Let Cloudflare minify; keep plugin critical CSS minify optional.', 'bosseo-accelerator' ); ?></em></p>
		</div>
		<?php
	}
}

