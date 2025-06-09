--- /dev/null
+++ libvirt_helpers.php
@@ -362,6 +362,11 @@
 		'icon' => 'openelec.png'
 	],
 
+	'UnRAID' => [
+		'form' => 'UnRAID.form.php',
+		'icon' => 'unraid.png'
+	],
+
 	' Linux ' => '', /* Linux Header */
 
 	'Linux' => [
@@ -493,6 +498,27 @@
 		'valid' => '0'
 	]
 ];
+
+$arrUnRAIDVersions = json_decode(file_get_contents('https://releases.unraid.net/usb-creator'), true);
+$arrUnRAIDVersions = array_merge(...array_map(
+	fn($os) => $os['subitems'] ?? [$os],
+	$arrUnRAIDVersions['os_list']
+));
+
+$arrUnRAIDVersions = array_column(
+	array_map(
+		fn($item) => [
+			'name' => substr($item['name'], 7),
+			'url' => $item['url'],
+			'size' => $item['image_download_size'],
+			'localpath' => '',
+			'valid' => 0
+		],
+		array_filter($arrUnRAIDVersions, fn($item) => $item['name'] !== 'Unraid OS Releases (Next Branch)')
+	),
+	null,
+	'name'
+);
 
 $fedora = '/var/tmp/fedora-virtio-isos';
 // set variable to obtained information
