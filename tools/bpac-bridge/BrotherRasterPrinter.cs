using System.Drawing.Printing;

namespace PartDb.BpacBridge;

internal static class BrotherRasterPrinter
{
    public const int Dpi = 180;
    public const int HeadPins = 128;
    public const int RasterBytes = 16;
    public const double MinLengthMm = 24.5;
    public const int MarginDots = 14;

    public static string? FindPrinter(string? preferred)
    {
        if (!string.IsNullOrWhiteSpace(preferred))
        {
            foreach (string name in PrinterSettings.InstalledPrinters)
            {
                if (name.Equals(preferred, StringComparison.OrdinalIgnoreCase))
                {
                    return name;
                }
            }
        }

        string? fallback = null;
        foreach (string name in PrinterSettings.InstalledPrinters)
        {
            if (name.Contains("PT-P700", StringComparison.OrdinalIgnoreCase)
                || name.Contains("PT-P750", StringComparison.OrdinalIgnoreCase))
            {
                return name;
            }

            if (fallback is null
                && (name.Contains("Brother PT", StringComparison.OrdinalIgnoreCase)
                    || name.Contains("P-touch", StringComparison.OrdinalIgnoreCase)))
            {
                fallback = name;
            }
        }

        return fallback;
    }

    public static IReadOnlyList<string> ListPrinters()
    {
        var names = new List<string>();
        foreach (string name in PrinterSettings.InstalledPrinters)
        {
            names.Add(name);
        }

        return names;
    }

    public static TapeSpec TapeForHeight(double heightMm)
    {
        if (heightMm >= 21)
        {
            return TapeSpec.Tze24;
        }

        if (heightMm >= 15)
        {
            return TapeSpec.Tze18;
        }

        return TapeSpec.Tze12;
    }

    public static int MmToDots(double mm) => Math.Max(1, (int)Math.Round(mm / 25.4 * Dpi));

    public static void Print(PrintJob job)
    {
        var printer = FindPrinter(job.Printer)
            ?? throw new InvalidOperationException("No Brother P-touch printer is installed.");

        var tape = TapeForHeight(job.HeightMm);
        var lengthMm = Math.Max(job.WidthMm, MinLengthMm);
        var totalDots = MmToDots(lengthMm);
        var rasterLines = Math.Max(1, totalDots - MarginDots * 2);

        AppLog.Write($"Raster print: printer={printer} tape={tape.WidthMm}mm length={lengthMm}mm raster={rasterLines} labels={job.Labels.Count} copies={job.Copies}");

        using var payload = new MemoryStream();
        WriteInvalidate(payload);
        payload.WriteByte(0x1B);
        payload.WriteByte(0x40);

        var lastIndex = job.Labels.Count - 1;
        for (var i = 0; i < job.Labels.Count; i++)
        {
            using var bitmap = LabelRenderer.Render(job.Labels[i], rasterLines, tape.PrintAreaDots, job.Layout);
            WritePage(payload, bitmap, tape, rasterLines, startingPage: i == 0, lastPage: i == lastIndex);
        }

        for (var copy = 1; copy < job.Copies; copy++)
        {
            for (var i = 0; i < job.Labels.Count; i++)
            {
                using var bitmap = LabelRenderer.Render(job.Labels[i], rasterLines, tape.PrintAreaDots, job.Layout);
                WritePage(payload, bitmap, tape, rasterLines, startingPage: false, lastPage: i == lastIndex);
            }
        }

        RawPrinter.Send(printer, payload.ToArray(), "Part-DB label");
    }

    private static void WritePage(MemoryStream payload, Bitmap bitmap, TapeSpec tape, int rasterLines, bool startingPage, bool lastPage)
    {
        payload.Write([0x1B, 0x69, 0x61, 0x01]);
        WritePrintInfo(payload, tape.WidthMm, rasterLines, startingPage);
        payload.Write([0x1B, 0x69, 0x4D, 0x40]);
        payload.Write([0x1B, 0x69, 0x4B, 0x08]);
        payload.Write([0x1B, 0x69, 0x64, MarginDots, 0x00]);
        payload.Write([0x4D, 0x00]);

        var line = new byte[RasterBytes];
        for (var x = 0; x < rasterLines; x++)
        {
            PackColumn(bitmap, tape, x, line);
            payload.WriteByte(0x67);
            payload.WriteByte(RasterBytes);
            payload.WriteByte(0x00);
            payload.Write(line);
        }

        payload.WriteByte(lastPage ? (byte)0x1A : (byte)0x0C);
    }

    private static void WritePrintInfo(MemoryStream payload, int tapeMm, int rasterLines, bool startingPage)
    {
        const byte flags = 0x80 | 0x04; // PI_RECOVER | PI_WIDTH
        payload.Write(
        [
            0x1B, 0x69, 0x7A,
            flags,
            0x00,
            (byte)tapeMm,
            0x00,
            (byte)(rasterLines & 0xFF),
            (byte)((rasterLines >> 8) & 0xFF),
            (byte)((rasterLines >> 16) & 0xFF),
            (byte)((rasterLines >> 24) & 0xFF),
            (byte)(startingPage ? 0x00 : 0x01),
            0x00,
        ]);
    }

    private static void PackColumn(Bitmap bitmap, TapeSpec tape, int x, byte[] line)
    {
        Array.Clear(line);
        var sourceX = Math.Min(x, bitmap.Width - 1);
        for (var pin = 0; pin < HeadPins; pin++)
        {
            var sourceY = pin - tape.OffsetDots;
            if (sourceY < 0 || sourceY >= bitmap.Height)
            {
                continue;
            }

            var color = bitmap.GetPixel(sourceX, sourceY);
            if (color.R > 160 && color.G > 160 && color.B > 160)
            {
                continue;
            }

            var byteIndex = pin / 8;
            var bit = 7 - (pin % 8);
            line[byteIndex] |= (byte)(1 << bit);
        }
    }

    private static void WriteInvalidate(MemoryStream payload)
    {
        var zeros = new byte[200];
        payload.Write(zeros);
    }
}

internal readonly record struct TapeSpec(int WidthMm, int PrintAreaDots, int OffsetDots)
{
    public static TapeSpec Tze12 { get; } = new(12, 70, 29);
    public static TapeSpec Tze18 { get; } = new(18, 112, 8);
    public static TapeSpec Tze24 { get; } = new(24, 128, 0);
}
