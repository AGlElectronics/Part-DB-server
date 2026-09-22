using System.Drawing.Drawing2D;
using System.Drawing.Imaging;
using System.Drawing.Text;
using QRCoder;

namespace PartDb.BpacBridge;

internal static class LabelRenderer
{
    public static Bitmap Render(PrintLabel label, int widthDots, int heightDots)
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
            DrawOneLine(graphics, label.Name, textRect, FontStyle.Bold);
        }

        return bitmap;
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

    private static void DrawOneLine(Graphics graphics, string text, Rectangle bounds, FontStyle style)
    {
        text = text.Replace('\n', ' ').Replace('\r', ' ').Trim();
        var format = new StringFormat
        {
            Alignment = StringAlignment.Center,
            LineAlignment = StringAlignment.Center,
            Trimming = StringTrimming.EllipsisCharacter,
            FormatFlags = StringFormatFlags.NoWrap,
        };
        using var font = new Font("Segoe UI", Math.Min(16f, Math.Max(11f, bounds.Height * 0.62f)), style, GraphicsUnit.Pixel);
        graphics.DrawString(text, font, Brushes.Black, bounds, format);
    }
}
