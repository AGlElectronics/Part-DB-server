# Part-DB P-touch bridge

Windows helper that prints Part-DB labels on a Brother PT-P700 at the **length set in the UI**.

Chrome's print dialog cannot force the Brother driver's tape length, so a 30 mm label becomes ~80 mm. This `.exe` sends Brother raster commands (and b-PAC if you add a `.lbx` template) so 30 mm is 30 mm.

## Install

1. Build (from a local disk — Google Drive / shared drives lock the apphost):

   ```powershell
   $src = Join-Path $env:TEMP "part-db-bpac-src"
   $out = Join-Path $env:TEMP "part-db-bpac-bridge-publish"
   Remove-Item $src, $out -Recurse -Force -ErrorAction SilentlyContinue
   New-Item -ItemType Directory -Force -Path $src | Out-Null
   Copy-Item .\*.cs, .\*.csproj, .\app.manifest $src
   dotnet publish $src\PartDb.BpacBridge.csproj -c Release -r win-x64 --self-contained false -o $out
   ```

   The exe is `%TEMP%\part-db-bpac-bridge-publish\PartDbBpacBridge.exe`. It needs the .NET 8 desktop runtime.

2. Run `PartDbBpacBridge.exe` and click **Install**, or:

   ```powershell
   .\PartDbBpacBridge.exe --install
   ```

   That copies the app to `%LOCALAPPDATA%\Part-DB\BpacBridge\` and registers the `partdb-bpac:` protocol and `.ptjob` files.

3. On the printer, **Editor Lite must be off**. If Windows shows the PT-P700 as a USB drive, hold the Editor Lite button until that goes away.

4. In Part-DB, generate a label and click **Print to P-touch**. The first time, Windows will ask which app should open `partdb-bpac:` — choose this exe.

## Job format

```json
{
  "version": 1,
  "width_mm": 30,
  "height_mm": 18,
  "copies": 1,
  "printer": "",
  "labels": [
    {
      "id": "1",
      "name": "DIN 912 M6 x 20",
      "description": "Hexagon socket head cap screw",
      "qr_url": "https://parts.4qt.org/en/scan/part/1"
    }
  ]
}
```

`width_mm` is the tape **length** (Part-DB width). `height_mm` is the tape **width** (18 or 24). Physical length is never shorter than 24.5 mm — that is the PT-P700 cutter minimum.

## Optional b-PAC template

The raster backend does not need the Brother b-PAC SDK. If you later want P-touch Editor objects, put `templates/stacked.lbx` next to the exe with objects named `name`, `description`, and `qr_url`. The bridge then sets `Document.Length` from `width_mm` and prints through b-PAC.

## CLI

| Command | Action |
| --- | --- |
| *(no args)* | Status window |
| `--install` | Register protocol and `.ptjob` |
| `--uninstall` | Remove registration |
| `--print job.ptjob` | Print a job file |
| `--test` | Print a 30×18 mm sample |
| `partdb-bpac:print,<base64url>` | Used by the Part-DB button |
