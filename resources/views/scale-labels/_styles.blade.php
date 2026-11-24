* { box-sizing: border-box; }
body { font-family: DejaVu Sans, sans-serif; font-size: {{ $fontSize }}pt; color: #000; margin: 0; padding: 0; line-height: 1.15; }
.label { position: absolute; width: {{ $width }}mm; height: {{ $height }}mm; overflow: hidden; }
.label-inner { padding: 1.5mm 2mm; text-align: center; }
.company { font-size: {{ max(5, $fontSize - 2) }}pt; font-weight: bold; }
.name { font-size: {{ $fontSize + 2 }}pt; font-weight: bold; }
.second { font-size: {{ max(5, $fontSize - 1) }}pt; }
.total { font-size: {{ $fontSize + 3 }}pt; font-weight: bold; margin: 0.5mm 0; }
.small { font-size: {{ max(5, $fontSize - 2) }}pt; }
table.row { width: 100%; border-collapse: collapse; }
table.row td { padding: 0; text-align: left; }
table.row td.end { text-align: right; }
.barcode { margin-top: 0.8mm; }
.barcode img { width: {{ max(10, $width - 6) }}mm; height: {{ max(6, round($height * 0.28, 1)) }}mm; }
.barcode-text { font-size: {{ max(5, $fontSize - 2) }}pt; letter-spacing: 0.3pt; }
