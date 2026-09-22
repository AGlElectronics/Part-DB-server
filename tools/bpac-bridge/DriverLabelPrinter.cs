using System.Globalization;
using System.Printing;
using System.Windows;
using System.Windows.Controls;
using System.Windows.Documents;
using System.Windows.Markup;
using System.Windows.Media;
using System.Windows.Media.Imaging;
using System.Xml;

namespace PartDb.BpacBridge;

/// <summary>
/// Prints through the Brother driver with an explicit tape length.
/// Raw raster jobs reach the status monitor and sit at 100% because that monitor
/// never gets a normal driver job to finish.
/// </summary>
internal static class DriverLabelPrinter
{
    private const string Psf = "http://schemas.microsoft.com/windows/2003/08/printing/printschemaframework";
    private const string Psk = "http://schemas.microsoft.com/windows/2003/08/printing/printschemakeywords";

    public static void Print(PrintJob job)
    {
        var printer = BrotherRasterPrinter.FindPrinter(job.Printer)
            ?? throw new InvalidOperationException("No Brother P-touch printer is installed.");

        var tape = BrotherRasterPrinter.TapeForHeight(job.HeightMm);
        var beside = string.Equals(job.Layout, "beside", StringComparison.OrdinalIgnoreCase);
        using var queue = new PrintQueue(new LocalPrintServer(), printer);
        // Brother leaves PageMediaSize.Width empty for custom tape. Landscape swaps
        // tape width and feed length, which matches the wide label preview.
        var pageHeight = MmToDip(tape.WidthMm);

        for (var copy = 0; copy < Math.Max(1, job.Copies); copy++)
        {
            foreach (var label in job.Labels)
            {
                var lengthMm = beside
                    ? LengthForBeside(label, tape)
                    : Math.Max(job.WidthMm, BrotherRasterPrinter.MinLengthMm);
                var ticket = CreateTicket(queue, lengthMm, tape.WidthMm);
                var pageWidth = MmToDip(lengthMm);
                AppLog.Write(
                    $"Driver print: printer={printer} tape={tape.WidthMm}mm printable={tape.PrintAreaDots}dots length={lengthMm:0.0}mm "
                    + $"page={pageWidth:0}x{pageHeight:0}dip layout={job.Layout}");
                var alongTapeIsWidth = pageWidth >= pageHeight;
                WritePage(queue, ticket, label, pageWidth, pageHeight, alongTapeIsWidth, tape, job.Layout, beside);
            }
        }
    }

    private static double LengthForBeside(PrintLabel label, TapeSpec tape)
    {
        var dots = LabelRenderer.BesideWidthDots(label, tape.PrintAreaDots);
        var mm = dots / (double)BrotherRasterPrinter.Dpi * 25.4;
        var snapped = Math.Ceiling(mm * 10.0) / 10.0;
        return Math.Max(BrotherRasterPrinter.MinLengthMm, snapped);
    }

    private static void WritePage(
        PrintQueue queue,
        PrintTicket ticket,
        PrintLabel label,
        double pageWidth,
        double pageHeight,
        bool alongTapeIsWidth,
        TapeSpec tape,
        string layout,
        bool beside)
    {
        var alongDots = Math.Max(1, (int)Math.Round((alongTapeIsWidth ? pageWidth : pageHeight) / 96.0 * BrotherRasterPrinter.Dpi));
        var acrossDots = tape.PrintAreaDots;
        using var bitmap = LabelRenderer.Render(label, alongDots, acrossDots, layout);
        if (!alongTapeIsWidth)
        {
            bitmap.RotateFlip(System.Drawing.RotateFlipType.Rotate90FlipNone);
        }

        var image = ToBitmapSource(bitmap);
        var fixedPage = new FixedPage
        {
            Width = pageWidth,
            Height = pageHeight,
            Background = System.Windows.Media.Brushes.White,
        };
        // The head is narrower than the tape. Stretching to the full cassette
        // pushes the QR into the top dead zone and the description into the bottom one.
        var printableMm = tape.PrintAreaDots / (double)BrotherRasterPrinter.Dpi * 25.4;
        var acrossDip = Math.Min(MmToDip(printableMm), alongTapeIsWidth ? pageHeight : pageWidth);
        // Beside labels are only as long as the name. Do not stretch that bitmap
        // out to a longer minimum cut, or the QR slides back into the cut zone.
        var nativeAlongDip = bitmap.Width / (double)BrotherRasterPrinter.Dpi * 96.0;
        var imageWidth = alongTapeIsWidth ? (beside ? Math.Min(nativeAlongDip, pageWidth) : pageWidth) : acrossDip;
        var imageHeight = alongTapeIsWidth ? acrossDip : (beside ? Math.Min(nativeAlongDip, pageHeight) : pageHeight);
        var control = new System.Windows.Controls.Image
        {
            Source = image,
            Width = imageWidth,
            Height = imageHeight,
            Stretch = Stretch.Fill,
        };
        FixedPage.SetLeft(control, alongTapeIsWidth ? 0 : (pageWidth - imageWidth) / 2.0);
        FixedPage.SetTop(control, alongTapeIsWidth ? (pageHeight - imageHeight) / 2.0 : 0);
        fixedPage.Children.Add(control);

        var document = new FixedDocument();
        document.DocumentPaginator.PageSize = new System.Windows.Size(pageWidth, pageHeight);
        var content = new PageContent();
        ((IAddChild)content).AddChild(fixedPage);
        document.Pages.Add(content);

        var writer = PrintQueue.CreateXpsDocumentWriter(queue);
        writer.Write(document.DocumentPaginator, ticket);
    }

    private static BitmapSource ToBitmapSource(System.Drawing.Bitmap bitmap)
    {
        using var stream = new MemoryStream();
        bitmap.Save(stream, System.Drawing.Imaging.ImageFormat.Png);
        stream.Position = 0;
        var image = new BitmapImage();
        image.BeginInit();
        image.CacheOption = BitmapCacheOption.OnLoad;
        image.StreamSource = stream;
        image.EndInit();
        image.Freeze();
        return image;
    }

    private static double MmToDip(double mm) => mm / 25.4 * 96.0;

    private static PrintTicket CreateTicket(PrintQueue queue, double lengthMm, int tapeMm)
    {
        using var source = queue.UserPrintTicket.GetXmlStream();
        var doc = new XmlDocument();
        doc.Load(source);

        var ns = new XmlNamespaceManager(doc.NameTable);
        ns.AddNamespace("psf", Psf);
        ns.AddNamespace("psk", Psk);

        var option = doc.SelectSingleNode("//psf:Feature[@name='psk:PageMediaSize']/psf:Option", ns) as XmlElement
            ?? throw new InvalidOperationException("The Brother driver ticket has no paper size.");
        var currentName = option.GetAttribute("name");
        var prefix = currentName.Contains(':') ? currentName.Split(':')[0] : "ns0001";
        var media = tapeMm >= 21 ? "CustomMediaSize261" : tapeMm >= 15 ? "CustomMediaSize260" : "CustomMediaSize259";
        option.SetAttribute("name", prefix + ":" + media);

        var microns = (int)Math.Round(lengthMm * 1000.0 / 100.0) * 100;
        var height = doc.SelectSingleNode("//psf:ParameterInit[@name='psk:PageMediaSizeMediaSizeHeight']/psf:Value", ns)
            ?? throw new InvalidOperationException("The Brother driver ticket has no length.");
        height.InnerText = microns.ToString(CultureInfo.InvariantCulture);

        using var output = new MemoryStream();
        doc.Save(output);
        output.Position = 0;
        var requested = new PrintTicket(output);
        var validated = queue.MergeAndValidatePrintTicket(queue.UserPrintTicket, requested, PrintTicketScope.JobScope);
        AppLog.Write($"Ticket {media} length={microns}um status={validated.ConflictStatus}");
        return validated.ValidatedPrintTicket;
    }
}
