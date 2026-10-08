後台面板標題列使用的 SVG 圖示。

來源：Lucide（lucide-static）https://lucide.dev/
版本：lucide-static v1.25.0
授權：ISC License（許可式開源授權，允許商業使用、修改、再散布）

每個 .svg 檔開頭都保留了原始的 `@license lucide-static v1.25.0 - ISC` 註解，
不要在最佳化或壓縮時把它拿掉——那是授權標示。

為什麼用 Lucide 而不是 WordPress 內建的 Dashicons：
本站的另一個自製外掛「終極電商」（twshop）後台已經用 Lucide，選它的理由是
細線條風格跟站方 Blocksy 主題一致。這裡沿用同一套，兩個外掛的後台並排時
才會看起來是同一個系列（見 assets/css/admin.css 的「區塊卡片」一節）。
Dashicons 是實心風格，混用會很明顯。

這裡只收錄實際會用到的圖示，不是完整的 Lucide 圖示集。要新增圖示時，從
lucide.dev 下載對應的 .svg 放進這個資料夾即可——輸出函式
UAPPT_Admin::icon() 會直接讀檔，不需要另外註冊；但檔名必須符合
`^[a-z0-9-]+$`（該函式的白名單規則）。
