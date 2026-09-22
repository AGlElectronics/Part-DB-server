using System.Drawing.Drawing2D;
using System.Drawing.Imaging;
using System.Drawing.Text;
using QRCoder;

namespace PartDb.BpacBridge;

internal static class LabelRenderer
{
    public static Bitmap Render(PrintLabel label, int widthDots, int heightDots, string? layout = null)
    {
        if (string.Equals(layout, "beside", StringComparison.OrdinalIgnoreCase))
        {
            return RenderBeside(label, widthDots, heightDots);
        }

        return RenderStacked(label, widthDots, heightDots);
    }

    private static Bitmap RenderStacked(PrintLabel label, int widthDots, int heightDots)
    {
        var bitmap = new Bitmap(Math.Max(widthDots, 1), Math.Max(heightDots, 1), PixelFormat.Format32bppArgb);
        using var graphics = Graphics.FromImage(bitmap);
        graphics.Clear(Color.White);
        graphics.SmoothingMode = SmoothingMode.None;
        graphics.InterpolationMode = InterpolationMode.NearestNeighbor;
        graphics.PixelOffsetMode = PixelOffsetMode.Half;
        graphics.TextRenderingHint = TextRenderingHint.SingleBitPerPixelGridFit;

        // Leave a solid black name line under the QR. Anti-aliased gray text
        // disappears on the thermal head, and a tight line box draws nothing.
        var padding = 4;
        var textBand = 28;
        var qrSize = Math.Min(widthDots - padding * 2, heightDots - padding - textBand - 6);
        qrSize = Math.Max(qrSize, 24);

        var qrUrl = string.IsNullOrWhiteSpace(label.QrUrl) ? "https://parts.4qt.org/" : label.QrUrl;
        using var qrImage = CreateQr(qrUrl, qrSize);
        var qrX = (widthDots - qrSize) / 2;
        graphics.DrawImage(qrImage, qrX, padding, qrSize, qrSize);

        var textTop = padding + qrSize + 2;
        var textHeight = heightDots - textTop - padding;
        if (textHeight >= 10 && !string.IsNullOrWhiteSpace(label.Name))
        {
            var textRect = new Rectangle(padding, textTop, widthDots - padding * 2, textHeight);
            DrawOneLine(graphics, label.Name, textRect, FontStyle.Bold, StringAlignment.Center);
        }

        return bitmap;
    }

    /// <summary>
    /// Width of a side-by-side label in dots, including the blank lead the cutter would otherwise eat.
    /// </summary>
    public static int BesideWidthDots(PrintLabel label, int heightDots)
    {
        var layout = MeasureBeside(label, heightDots);
        return layout.LeadDots + layout.QrSize + layout.GapDots + layout.TextDots + layout.TrailDots;
    }

    private static Bitmap RenderBeside(PrintLabel label, int widthDots, int heightDots)
    {
        var layout = MeasureBeside(label, heightDots);
        var contentDots = layout.LeadDots + layout.QrSize + layout.GapDots + layout.TextDots + layout.TrailDots;
        var bitmap = new Bitmap(Math.Max(contentDots, 1), Math.Max(heightDots, 1), PixelFormat.Format32bppArgb);
        using var graphics = Graphics.FromImage(bitmap);
        graphics.Clear(Color.White);
        graphics.SmoothingMode = SmoothingMode.None;
        graphics.InterpolationMode = InterpolationMode.NearestNeighbor;
        graphics.PixelOffsetMode = PixelOffsetMode.Half;
        graphics.TextRenderingHint = TextRenderingHint.SingleBitPerPixelGridFit;

        var qrUrl = string.IsNullOrWhiteSpace(label.QrUrl) ? "https://parts.4qt.org/" : label.QrUrl;
        using var qrImage = CreateQr(qrUrl, layout.QrSize);
        var qrY = (heightDots - layout.QrSize) / 2;
        graphics.DrawImage(qrImage, layout.LeadDots, qrY, layout.QrSize, layout.QrSize);

        if (layout.TextDots > 0)
        {
            var textLeft = layout.LeadDots + layout.QrSize + layout.GapDots;
            var textRect = new Rectangle(textLeft, layout.Padding, layout.TextDots, heightDots - layout.Padding * 2);
            DrawOneLine(graphics, label.Name, textRect, FontStyle.Bold, StringAlignment.Near, 26f, StringTrimming.None);
        }

        _ = widthDots;
        return bitmap;
    }

    private readonly record struct BesideLayout(int LeadDots, int TrailDots, int Padding, int GapDots, int QrSize, int TextDots);

    private static BesideLayout MeasureBeside(PrintLabel label, int heightDots)
    {
        // The PT-P700 cuts into both ends of the tape. Keep the QR and the name out of that zone.
        const int leadDots = 36;
        const int trailDots = 36;
        const int padding = 3;
        const int gapDots = 6;
        heightDots = Math.Max(heightDots, padding * 2 + 16);
        var qrSize = heightDots - padding * 2;
        var text = (label.Name ?? "").Replace('\n', ' ').Replace('\r', ' ').Trim();
        var textDots = 0;
        if (text.Length > 0)
        {
            var fontPx = Math.Min(26f, Math.Max(11f, (heightDots - padding * 2) * 0.62f));
            using var probe = new Bitmap(1200, Math.Max(heightDots, 32), PixelFormat.Format32bppArgb);
            using var graphics = Graphics.FromImage(probe);
            graphics.TextRenderingHint = TextRenderingHint.SingleBitPerPixelGridFit;
            using var font = new Font("Segoe UI", fontPx, FontStyle.Bold, GraphicsUnit.Pixel);
            using var format = new StringFormat
            {
                Alignment = StringAlignment.Near,
                LineAlignment = StringAlignment.Center,
                Trimming = StringTrimming.None,
                FormatFlags = StringFormatFlags.NoWrap | StringFormatFlags.MeasureTrailingSpaces,
            };
            var measured = graphics.MeasureString(text, font, new SizeF(2000, heightDots), format);
            textDots = (int)Math.Ceiling(measured.Width) + (int)Math.Ceiling(fontPx);
        }

        return new BesideLayout(leadDots, trailDots, padding, gapDots, qrSize, textDots);
    }

    private static Bitmap CreateQr(string content, int size)
    {
        using var generator = new QRCodeGenerator();
        using var data = generator.CreateQrCode(content, QRCodeGenerator.ECCLevel.M);
        using var qr = new QRCode(data);
        using var raw = qr.GetGraphic(4, Color.Black, Color.White, drawQuietZones: false);
        var scaled = new Bitmap(size, size, PixelFormat.Format32bppArgb);
        using var graphics = Graphics.FromImage(scaled);
        graphics.SmoothingMode = SmoothingMode.None;
        graphics.InterpolationMode = InterpolationMode.NearestNeighbor;
        graphics.PixelOffsetMode = PixelOffsetMode.Half;
        graphics.Clear(Color.White);
        graphics.DrawImage(raw, 0, 0, size, size);
        return scaled;
    }

    private static void DrawOneLine(Graphics graphics, string text, Rectangle bounds, FontStyle style, StringAlignment alignment, float maxFont = 16f, StringTrimming trimming = StringTrimming.EllipsisCharacter)
    {
        text = text.Replace('\n', ' ').Replace('\r', ' ').Trim();
        using var format = new StringFormat
        {
            Alignment = alignment,
            LineAlignment = StringAlignment.Center,
            Trimming = trimming,
            FormatFlags = StringFormatFlags.NoWrap,
        };
        using var font = new Font("Segoe UI", Math.Min(maxFont, Math.Max(11f, bounds.Height * 0.62f)), style, GraphicsUnit.Pixel);
        graphics.DrawString(text, font, Brushes.Black, bounds, format);
    }
}
