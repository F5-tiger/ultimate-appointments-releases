<?php
/**
 * 線上更新：讓各站台後台直接從 GitHub 收到新版本。
 *
 * 版本來源是**公開**的發版倉庫 F5-tiger/ultimate-appointments-releases，裡面只放安裝版
 * 的檔案（跟桌面那包安裝版 zip 同一份內容），每個版本一個 tag（v3.1.1 這種）。原始碼
 * 留在私有倉庫——這樣客戶站不需要內嵌任何 GitHub token，原始碼也不公開。
 *
 * 實際檢查交給 plugin-update-checker（includes/vendor/，MIT 授權）：它每 12 小時問一次
 * GitHub「最高的 tag 是幾版」，比站上的 Version 新就塞進 WordPress 的更新清單，後台
 * 看起來跟一般外掛更新一模一樣。GitHub 自動產生的 tag zip 解開後資料夾名稱會帶版號，
 * 它也會在安裝時改回 ultimate-appointments/，不然 WordPress 會當成另一支外掛。
 *
 * ⚠️ 刻意不放在 uappt_bootstrap() 裡：那裡在 WooCommerce 沒啟用時會整個 return。
 * 更新機制要在任何狀況下都活著——萬一哪一版出了問題，修正版還送得進去。
 *
 * @package Ultimate_Appointments
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class UAPPT_Updater {

	/**
	 * 公開發版倉庫。改這裡的話，外掛標頭的 Update URI 要一起改。
	 */
	const REPO = 'https://github.com/F5-tiger/ultimate-appointments-releases/';

	/**
	 * 主檔路徑，供 auto_update_plugin 比對用。
	 *
	 * @var string
	 */
	private static $plugin_file = '';

	/**
	 * @param string $plugin_file 外掛主檔的完整路徑（__FILE__）。
	 */
	public static function init( $plugin_file ) {
		// 開發用的那份（teat01）資料夾裡有 .git：WordPress 更新外掛是「整個資料夾刪掉
		// 換新的」，在這裡按下更新（或被自動更新）會把 git 歷史、CLAUDE.md、docs/ 一起
		// 刪光。安裝版 zip 一定不含 .git，所以這個判斷只會擋到開發機。
		if ( file_exists( dirname( $plugin_file ) . '/.git' ) ) {
			return;
		}

		self::$plugin_file = $plugin_file;

		require_once UAPPT_PLUGIN_DIR . 'includes/vendor/plugin-update-checker/plugin-update-checker.php';

		$checker = \YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
			self::REPO,
			$plugin_file,
			'ultimate-appointments'
		);
		// 分支設成 main 才會走「最新 release → 最高版號 tag」的判斷；設成其他分支的話，
		// 每個 commit 都會被當成新版，那就不是「發版」了。
		$checker->setBranch( 'main' );

		add_filter( 'auto_update_plugin', array( __CLASS__, 'force_auto_update' ), 10, 2 );
	}

	/**
	 * 預設開啟自動更新：店家不用自己去外掛列表按「啟用自動更新」。
	 *
	 * 個別站台若要改回手動（例如上線活動期間不想有任何變動），在 wp-config.php 加
	 * `define( 'UAPPT_DISABLE_AUTO_UPDATE', true );`，就會回到後台開關自己決定。
	 *
	 * @param bool|null $update 原本的判斷。
	 * @param object    $item   這次要更新的項目。
	 * @return bool|null
	 */
	public static function force_auto_update( $update, $item ) {
		if ( defined( 'UAPPT_DISABLE_AUTO_UPDATE' ) && UAPPT_DISABLE_AUTO_UPDATE ) {
			return $update;
		}
		if ( isset( $item->plugin ) && plugin_basename( self::$plugin_file ) === $item->plugin ) {
			return true;
		}
		return $update;
	}
}
