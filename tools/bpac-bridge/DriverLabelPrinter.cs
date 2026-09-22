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

        var lengthMm = Math.Max(job.WidthMm, BrotherRasterPrinter.MinLengthMm);
        var tape = BrotherRasterPrinter.TapeForHeight(job.HeightMm);
        using var queue = new PrintQueue(new LocalPrintServer(), printer);
        var ticket = CreateTicket(queue, lengthMm, tape.WidthMm);
        // Brother leaves PageMediaSize.Width empty for custom tape. Landscape swaps
        // tape width and feed length, which matches the wide label preview.
        var pageWidth = MmToDip(lengthMm);
        var pageHeight = MmToDip(tape.WidthMm);

        AppLog.Write(
            $"Driver print: printer={printer} tape={tape.WidthMm}mm printable={tape.PrintAreaDots}dots length={lengthMm}mm "
            + $"page={pageWidth:0}x{pageHeight:0}dip labels={job.Labels.Count} copies={job.Copies}");
        var alongTapeIsWidth = pageWidth >= pageHeight;

        for (var copy = 0; copy < Math.Max(1, job.Copies); copy++)
        {
            foreach (var label in job.Labels)
            {
                WritePage(queue, ticket, label, pageWidth, pageHeight, alongTapeIsWidth, tape);
            }
        }
    }

    private static void WritePage(
        PrintQueue queue,
        PrintTicket ticket,
        PrintLabel label,
        double pageWidth,
        double pageHeight,
        bool alongTapeIsWidth,
        TapeSpec tape)
    {
        var alongDots = Math.Max(1, (int)Math.Round((alongTapeIsWidth ? pageWidth : pageHeight) / 96.0 * BrotherRasterPrinter.Dpi));
        var acrossDots = tape.PrintAreaDots;
        using var bitmap = LabelRenderer.Render(label, alongDots, acrossDots);
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
        var imageWidth = alongTapeIsWidth ? pageWidth : acrossDip;
        var imageHeight = alongTapeIsWidth ? acrossDip : pageHeight;
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
        var media = tapeMm >= 21 ? "CustomMediaSize261" : "CustomMediaSize260";
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
