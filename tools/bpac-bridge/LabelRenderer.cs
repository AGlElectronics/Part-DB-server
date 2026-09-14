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
        graphics.TextRenderingHint = TextRenderingHint.AntiAliasGridFit;

        var padding = Math.Max(2, heightDots / 32);
        var qrSize = Math.Min(heightDots * 11 / 18, heightDots - padding * 2);
        qrSize = Math.Max(qrSize, 24);

        var qrUrl = string.IsNullOrWhiteSpace(label.QrUrl) ? "https://parts.4qt.org/" : label.QrUrl;
        using var qrImage = CreateQr(qrUrl, qrSize);
        var qrX = (widthDots - qrSize) / 2;
        graphics.DrawImage(qrImage, qrX, padding, qrSize, qrSize);

        var textTop = padding + qrSize + Math.Max(1, padding / 2);
        var textHeight = heightDots - textTop - padding;
        if (textHeight < 8)
        {
            return bitmap;
        }

        var nameHeight = Math.Max(textHeight * 55 / 100, 8);
        var descHeight = textHeight - nameHeight;
        var textRect = new Rectangle(padding, textTop, widthDots - padding * 2, nameHeight);
        DrawFittedText(graphics, label.Name, textRect, FontStyle.Bold, Math.Max(7f, heightDots * 0.13f));

        if (descHeight >= 7 && !string.IsNullOrWhiteSpace(label.Description))
        {
            var descRect = new Rectangle(padding, textTop + nameHeight, widthDots - padding * 2, descHeight);
            DrawFittedText(graphics, label.Description, descRect, FontStyle.Regular, Math.Max(6f, heightDots * 0.11f));
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

    private static void DrawFittedText(Graphics graphics, string text, Rectangle bounds, FontStyle style, float startSize)
    {
        if (string.IsNullOrWhiteSpace(text) || bounds.Width < 4 || bounds.Height < 4)
        {
            return;
        }

        text = text.Replace('\n', ' ').Replace('\r', ' ').Trim();
        var format = new StringFormat
        {
            Alignment = StringAlignment.Center,
            LineAlignment = StringAlignment.Center,
            Trimming = StringTrimming.EllipsisCharacter,
            FormatFlags = StringFormatFlags.NoWrap,
        };

        for (var size = startSize; size >= 5f; size -= 0.5f)
        {
            using var font = new Font("Segoe UI", size, style, GraphicsUnit.Pixel);
            var measured = graphics.MeasureString(text, font, bounds.Width, format);
            if (measured.Width <= bounds.Width + 1 && measured.Height <= bounds.Height + 1)
            {
                graphics.DrawString(text, font, Brushes.Black, bounds, format);
                return;
            }
        }

        using var fallback = new Font("Segoe UI", 5f, style, GraphicsUnit.Pixel);
        graphics.DrawString(text, fallback, Brushes.Black, bounds, format);
    }
}
