<?php
/**
 * 舊代號 → 新代號的一次性資料遷移（v3.0.0 改名：wc-service-booking／WCSB_／wcsb_ →
 * ultimate-appointments／UAPPT_／uappt_）。
 *
 * 計畫見 docs/rename-plan.md 第 4 節。這個檔案裡出現的 `wcsb` 都是**刻意的**——它就是
 * 要找出舊名稱的資料搬過來；改名時的全域取代不會、也不該碰到這裡（它是改名之後才寫的）。
 *
 * ⚠️ **一定要在建資料表之前跑**（外掛載入時排在 UAPPT_Install::maybe_upgrade() 前面、
 * 啟用時排在 install() 前面）：先建了空的 `wp_uappt_*` 資料表，舊資料就搬不進去了。
 * 為了保險，新表已經存在但是空的，會先刪掉再搬；兩邊都有資料就整個停下來，不猜。
 *
 * 每一步都可以重跑（條件都帶著舊名稱，搬完就找不到了），全部做完才記完成旗標。
 *
 * @package Ultimate_Appointments
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class UAPPT_Legacy_Migration {

	const DONE_OPTION   = 'uappt_legacy_migrated';
	const REPORT_OPTION = 'uappt_legacy_migration_report';

	const OLD_PREFIX = 'wcsb';
	const NEW_PREFIX = 'uappt';

	/**
	 * 舊外掛的主檔（資料夾也改名了）。新外掛啟用時如果它還開著，要把它關掉。
	 */
	const OLD_PLUGIN_FILE = 'wc-service-booking/wc-service-booking.php';

	/**
	 * 外掛自己的資料表（不含前綴）。跟 UAPPT_Install::install() 建的那幾張一致。
	 */
	const TABLES = array(
		'staff',
		'staff_overrides',
		'slot_grid',
		'bookings',
		'consumables',
		'consumable_recipes',
		'consumable_moves',
		'customer_contacts',
		'pools',
		'pool_overrides',
		'shift_requests',
	);

	/**
	 * 需要遷移就遷移。沒有任何舊資料（全新安裝）時直接記完成，之後每次請求只剩一次
	 * get_option() 的成本。
	 */
	public static function maybe_run() {
		if ( get_option( self::DONE_OPTION ) ) {
			return;
		}

		if ( ! self::has_legacy_data() ) {
			update_option( self::DONE_OPTION, 1, true );
			return;
		}

		self::run();
	}

	/**
	 * 有沒有舊代號的資料：舊的資料庫版本選項，或任何一張舊資料表。
	 *
	 * @return bool
	 */
	public static function has_legacy_data() {
		global $wpdb;

		if ( false !== get_option( self::OLD_PREFIX . '_db_version', false ) ) {
			return true;
		}

		return (bool) $wpdb->get_var(
			$wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $wpdb->prefix . self::OLD_PREFIX . '_' ) . '%' )
		);
	}

	/**
	 * 整套遷移。
	 *
	 * @return array 每一步的筆數（也存進 REPORT_OPTION，除錯用）。
	 */
	public static function run() {
		$report = array( 'started' => current_time( 'mysql' ) );

		$tables = self::tables();
		$report['tables'] = $tables;
		if ( ! empty( $tables['conflicts'] ) ) {
			// 新舊兩邊都有資料：不知道哪一份才是對的，停下來，不記完成——下次請求會
			// 再試一次，問題排除前資料都不會被動到。
			$report['stopped'] = 'table conflict';
			update_option( self::REPORT_OPTION, $report, false );
			return $report;
		}

		$report['options']  = self::options();
		$report['meta']     = self::meta_keys();
		$report['roles']    = self::roles();
		$report['users']    = self::users();
		$report['sessions'] = self::sessions();
		$report['values']   = self::plain_values();
		$report['content']  = self::content();
		$report['cron']     = self::cron();
		$report['plugin']   = self::deactivate_old_plugin();

		// 「我的帳戶」的網址換了（uappt-bookings…），rewrite 規則要重刷；排程由新外掛
		// 自己重排（舊的 hook 名稱已經清掉了）。
		update_option( self::NEW_PREFIX . '_flush_rewrite_rules', 1 );
		if ( class_exists( 'UAPPT_Cron' ) ) {
			UAPPT_Cron::schedule_events();
		}

		wp_cache_flush();

		$report['finished'] = current_time( 'mysql' );
		update_option( self::REPORT_OPTION, $report, false );
		update_option( self::DONE_OPTION, 1, true );

		return $report;
	}

	/**
	 * 資料表改名。
	 *
	 * @return array{renamed:int, conflicts:array}
	 */
	protected static function tables() {
		global $wpdb;

		$out = array(
			'renamed'   => 0,
			'conflicts' => array(),
		);

		foreach ( self::TABLES as $name ) {
			$old = $wpdb->prefix . self::OLD_PREFIX . '_' . $name;
			$new = $wpdb->prefix . self::NEW_PREFIX . '_' . $name;

			if ( ! self::table_exists( $old ) ) {
				continue;
			}

			if ( self::table_exists( $new ) ) {
				$rows = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$new}`" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				if ( $rows > 0 ) {
					$out['conflicts'][] = $name;
					continue;
				}
				$wpdb->query( "DROP TABLE `{$new}`" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			}

			$wpdb->query( "RENAME TABLE `{$old}` TO `{$new}`" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$out['renamed']++;
		}

		return $out;
	}

	/**
	 * 選項改名；暫存（transient）直接刪掉讓它重算——搬一份過期的快取沒有意義。
	 *
	 * @return array{renamed:int, transients:int}
	 */
	protected static function options() {
		global $wpdb;

		$transients = (int) $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s",
				$wpdb->esc_like( '_transient_' . self::OLD_PREFIX . '_' ) . '%',
				$wpdb->esc_like( '_transient_timeout_' . self::OLD_PREFIX . '_' ) . '%',
				$wpdb->esc_like( '_site_transient_' . self::OLD_PREFIX . '_' ) . '%',
				$wpdb->esc_like( '_site_transient_timeout_' . self::OLD_PREFIX . '_' ) . '%'
			)
		);

		$names = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s",
				$wpdb->esc_like( self::OLD_PREFIX . '_' ) . '%'
			)
		);

		$renamed = 0;
		foreach ( $names as $old ) {
			$new = self::NEW_PREFIX . substr( $old, strlen( self::OLD_PREFIX ) );
			// 新名稱已經有一筆（新外掛先寫了預設值）：舊的才是使用者真正的設定，蓋過去。
			$wpdb->delete( $wpdb->options, array( 'option_name' => $new ) );
			$wpdb->update( $wpdb->options, array( 'option_name' => $new ), array( 'option_name' => $old ) );
			$renamed++;
		}

		return array(
			'renamed'    => $renamed,
			'transients' => $transients,
		);
	}

	/**
	 * meta 的鍵：商品、使用者、分類、留言、訂單項目、HPOS 訂單。
	 *
	 * 只認「wcsb 開頭」與「_wcsb 開頭」的鍵——別的外掛的鍵剛好含有這四個字母的話不該被動到。
	 *
	 * @return array 資料表 => 改了幾筆
	 */
	protected static function meta_keys() {
		global $wpdb;

		$tables = array(
			$wpdb->postmeta,
			$wpdb->usermeta,
			$wpdb->termmeta,
			$wpdb->commentmeta,
			$wpdb->prefix . 'woocommerce_order_itemmeta',
			$wpdb->prefix . 'wc_orders_meta',
		);

		$out = array();
		foreach ( $tables as $table ) {
			if ( ! self::table_exists( $table ) ) {
				continue;
			}
			$out[ $table ] = (int) $wpdb->query(
				$wpdb->prepare(
					"UPDATE `{$table}` SET meta_key = CONCAT( %s, SUBSTRING( meta_key, %d ) ) WHERE meta_key LIKE %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					self::NEW_PREFIX,
					strlen( self::OLD_PREFIX ) + 1,
					$wpdb->esc_like( self::OLD_PREFIX ) . '%'
				)
			);
			$out[ $table ] += (int) $wpdb->query(
				$wpdb->prepare(
					"UPDATE `{$table}` SET meta_key = CONCAT( %s, SUBSTRING( meta_key, %d ) ) WHERE meta_key LIKE %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					'_' . self::NEW_PREFIX,
					strlen( self::OLD_PREFIX ) + 2,
					$wpdb->esc_like( '_' . self::OLD_PREFIX ) . '%'
				)
			);
		}

		return $out;
	}

	/**
	 * 角色與權限：自訂角色 wcsb_staff → uappt_staff，各角色身上的 wcsb_* 權限 → uappt_*。
	 *
	 * ⚠️ 角色存在 `{prefix}user_roles` 這個選項裡，是 **PHP 序列化**的陣列——序列化字串
	 * 帶長度，wcsb→uappt 多一個字，直接字串取代會把整份角色設定弄壞、讀出來變成 false
	 * （全站的人都會失去所有權限）。所以一定要解開、改鍵、再存回去。
	 *
	 * @return array{roles:int, caps:int}
	 */
	protected static function roles() {
		global $wpdb;

		$key   = $wpdb->prefix . 'user_roles';
		$roles = get_option( $key );
		if ( ! is_array( $roles ) ) {
			return array( 'roles' => 0, 'caps' => 0 );
		}

		$renamed_roles = 0;
		$renamed_caps  = 0;
		$out           = array();
		foreach ( $roles as $role_key => $role ) {
			$new_key = self::rename_string( $role_key );
			if ( $new_key !== $role_key ) {
				$renamed_roles++;
			}

			$caps = array();
			foreach ( isset( $role['capabilities'] ) ? (array) $role['capabilities'] : array() as $cap => $grant ) {
				$new_cap = self::rename_string( $cap );
				if ( $new_cap !== $cap ) {
					$renamed_caps++;
				}
				$caps[ $new_cap ] = $grant;
			}
			$role['capabilities'] = $caps;
			$out[ $new_key ]      = $role;
		}

		if ( $renamed_roles || $renamed_caps ) {
			update_option( $key, $out );
			// 這次請求裡已經載入的角色物件也要換掉，不然後面的程式看到的還是舊的。
			if ( function_exists( 'wp_roles' ) ) {
				wp_roles()->for_site();
			}
		}

		return array( 'roles' => $renamed_roles, 'caps' => $renamed_caps );
	}

	/**
	 * WooCommerce 的訪客／會員工作階段（購物車、預約精靈的返回網址）。
	 *
	 * 會過期的暫存資料，但改版當下正在結帳的客人購物車裡就帶著預約資料——鍵名沒換，
	 * 新程式認不得，那筆預約商品會變成沒有預約內容的普通商品。
	 *
	 * ⚠️ 兩層序列化：每個值先各自序列化（`customer`、`cart`…），整包再序列化一次，
	 * 所以靠 rename_deep() 遇到序列化字串會再解開一層。
	 *
	 * @return int 改了幾筆
	 */
	protected static function sessions() {
		global $wpdb;

		$table = $wpdb->prefix . 'woocommerce_sessions';
		if ( ! self::table_exists( $table ) ) {
			return 0;
		}

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT session_id, session_value FROM {$table} WHERE session_value LIKE %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				'%' . $wpdb->esc_like( self::OLD_PREFIX ) . '%'
			),
			ARRAY_A
		);

		$changed = 0;
		foreach ( $rows as $row ) {
			$new = self::rename_value( $row['session_value'] );
			if ( $new !== $row['session_value'] ) {
				$wpdb->update( $table, array( 'session_value' => $new ), array( 'session_id' => (int) $row['session_id'] ) );
				$changed++;
			}
		}

		return $changed;
	}

	/**
	 * 使用者身上的東西：角色／個別權限（`{prefix}capabilities`）、存著的購物車等序列化值。
	 *
	 * 一樣要解開再改（理由同 roles()）。
	 *
	 * @return int 改了幾筆
	 */
	protected static function users() {
		global $wpdb;

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT umeta_id, meta_value FROM {$wpdb->usermeta} WHERE meta_value LIKE %s",
				'%' . $wpdb->esc_like( self::OLD_PREFIX ) . '%'
			),
			ARRAY_A
		);

		$changed = 0;
		foreach ( $rows as $row ) {
			$new = self::rename_value( $row['meta_value'] );
			if ( $new !== $row['meta_value'] ) {
				$wpdb->update( $wpdb->usermeta, array( 'meta_value' => $new ), array( 'umeta_id' => (int) $row['umeta_id'] ) );
				$changed++;
			}
		}

		if ( $changed ) {
			wp_cache_flush();
		}

		return $changed;
	}

	/**
	 * 存成「值」的代號：商品類型的分類詞（wcsb_booking）與訂單來源（wcsb_manual）。
	 *
	 * @return array
	 */
	protected static function plain_values() {
		global $wpdb;

		$out = array();

		// 商品類型：WooCommerce 用 product_type 分類的詞記每個商品是哪一種。
		$out['product_type'] = (int) $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->terms} t
				 JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = t.term_id AND tt.taxonomy = 'product_type'
				 SET t.slug = %s, t.name = %s
				 WHERE t.slug = %s",
				self::NEW_PREFIX . '_booking',
				self::NEW_PREFIX . '_booking',
				self::OLD_PREFIX . '_booking'
			)
		);

		// 訂單來源：後台現場建單記成 wcsb_manual。HPOS 存在 operational_data，舊式訂單
		// 存在 postmeta 的 _created_via，兩邊都改。
		$hpos = $wpdb->prefix . 'wc_order_operational_data';
		if ( self::table_exists( $hpos ) ) {
			$out['created_via_hpos'] = (int) $wpdb->query(
				$wpdb->prepare(
					"UPDATE `{$hpos}` SET created_via = CONCAT( %s, SUBSTRING( created_via, %d ) ) WHERE created_via LIKE %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					self::NEW_PREFIX,
					strlen( self::OLD_PREFIX ) + 1,
					$wpdb->esc_like( self::OLD_PREFIX . '_' ) . '%'
				)
			);
		}
		$out['created_via_meta'] = (int) $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->postmeta} SET meta_value = CONCAT( %s, SUBSTRING( meta_value, %d ) ) WHERE meta_key = '_created_via' AND meta_value LIKE %s",
				self::NEW_PREFIX,
				strlen( self::OLD_PREFIX ) + 1,
				$wpdb->esc_like( self::OLD_PREFIX . '_' ) . '%'
			)
		);

		return $out;
	}

	/**
	 * 頁面內容裡的短代碼與 Elementor 存的 widget。
	 *
	 * 短代碼與 `_elementor_data`（JSON，不是 PHP 序列化）可以直接字串取代；
	 * `_elementor_page_assets` 是序列化陣列，要解開再改。做完清掉 Elementor 產生的 CSS／
	 * 元件快取，讓它照新的 class 重新產生。
	 *
	 * ⚠️ 不動商品網址、SKU、訂單歸因裡記的舊後台網址——那是店家的內容與歷史。
	 *
	 * @return array
	 */
	protected static function content() {
		global $wpdb;

		$out = array();

		$out['shortcodes'] = (int) $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->posts} SET post_content = REPLACE( REPLACE( post_content, %s, %s ), %s, %s ) WHERE post_content LIKE %s OR post_content LIKE %s",
				'[' . self::OLD_PREFIX . '_',
				'[' . self::NEW_PREFIX . '_',
				'[/' . self::OLD_PREFIX . '_',
				'[/' . self::NEW_PREFIX . '_',
				'%' . $wpdb->esc_like( '[' . self::OLD_PREFIX . '_' ) . '%',
				'%' . $wpdb->esc_like( '[/' . self::OLD_PREFIX . '_' ) . '%'
			)
		);

		$out['elementor_data'] = (int) $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->postmeta} SET meta_value = REPLACE( meta_value, %s, %s ) WHERE meta_key = '_elementor_data' AND meta_value LIKE %s",
				self::OLD_PREFIX,
				self::NEW_PREFIX,
				'%' . $wpdb->esc_like( self::OLD_PREFIX ) . '%'
			)
		);

		$assets = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT meta_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_elementor_page_assets' AND meta_value LIKE %s",
				'%' . $wpdb->esc_like( self::OLD_PREFIX ) . '%'
			),
			ARRAY_A
		);
		$out['elementor_assets'] = 0;
		foreach ( $assets as $row ) {
			$wpdb->update( $wpdb->postmeta, array( 'meta_value' => self::rename_value( $row['meta_value'] ) ), array( 'meta_id' => (int) $row['meta_id'] ) );
			$out['elementor_assets']++;
		}

		if ( $out['elementor_data'] || $out['elementor_assets'] ) {
			delete_post_meta_by_key( '_elementor_css' );
			delete_post_meta_by_key( '_elementor_element_cache' );
			delete_option( '_elementor_global_css' );
		}

		return $out;
	}

	/**
	 * 清掉舊名稱的排程（新外掛會用新名稱自己排）。
	 *
	 * @return int 清掉幾個 hook
	 */
	protected static function cron() {
		$cron = _get_cron_array();
		if ( ! is_array( $cron ) ) {
			return 0;
		}

		$hooks = array();
		foreach ( $cron as $events ) {
			foreach ( (array) $events as $hook => $unused ) {
				if ( 0 === strpos( $hook, self::OLD_PREFIX . '_' ) ) {
					$hooks[ $hook ] = true;
				}
			}
		}

		foreach ( array_keys( $hooks ) as $hook ) {
			wp_clear_scheduled_hook( $hook );
		}

		return count( $hooks );
	}

	/**
	 * 舊資料夾的外掛如果還開著，關掉——兩份同時載入會各自註冊一次商品類型、排程與選單。
	 *
	 * @return bool 有沒有關掉
	 */
	protected static function deactivate_old_plugin() {
		$active = (array) get_option( 'active_plugins', array() );
		if ( ! in_array( self::OLD_PLUGIN_FILE, $active, true ) ) {
			return false;
		}
		update_option( 'active_plugins', array_values( array_diff( $active, array( self::OLD_PLUGIN_FILE ) ) ) );
		return true;
	}

	/**
	 * 一個字串裡的舊代號換成新代號（三種大小寫）。
	 *
	 * @param string $s 字串。
	 * @return string
	 */
	protected static function rename_string( $s ) {
		return str_replace(
			array( 'WCSB', 'Wcsb', self::OLD_PREFIX ),
			array( 'UAPPT', 'Uappt', self::NEW_PREFIX ),
			(string) $s
		);
	}

	/**
	 * 一個 meta／選項的值：序列化的就解開、遞迴改鍵與字串值、再序列化回去；
	 * 不是序列化的就直接字串取代。
	 *
	 * @param string $raw 資料庫裡的原始值。
	 * @return string
	 */
	protected static function rename_value( $raw ) {
		if ( ! is_serialized( $raw ) ) {
			return self::rename_string( $raw );
		}

		$value = @unserialize( $raw, array( 'allowed_classes' => false ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.PHP.DiscouragedPHPFunctions
		if ( false === $value && 'b:0;' !== $raw ) {
			return $raw; // 解不開就原樣留著，不要把一筆壞掉的資料改得更壞。
		}

		return serialize( self::rename_deep( $value ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions
	}

	/**
	 * 遞迴：陣列的鍵與值、字串都換成新代號。
	 *
	 * 字串本身又是序列化的（WooCommerce 工作階段、外掛存進陣列裡的序列化值）就再解開
	 * 一層——直接字串取代會讓 `s:長度:` 對不上，整筆解不開。
	 *
	 * @param mixed $value 值。
	 * @return mixed
	 */
	protected static function rename_deep( $value ) {
		if ( is_array( $value ) ) {
			$out = array();
			foreach ( $value as $k => $v ) {
				$out[ is_string( $k ) ? self::rename_string( $k ) : $k ] = self::rename_deep( $v );
			}
			return $out;
		}
		if ( ! is_string( $value ) ) {
			return $value;
		}
		return is_serialized( $value ) ? self::rename_value( $value ) : self::rename_string( $value );
	}

	/**
	 * @param string $table 完整資料表名稱。
	 * @return bool
	 */
	protected static function table_exists( $table ) {
		global $wpdb;
		return $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
	}
}
