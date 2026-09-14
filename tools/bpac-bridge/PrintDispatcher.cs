using System.Text;

namespace PartDb.BpacBridge;

internal static class PrintDispatcher
{
    public static PrintJob ParseArgument(string argument)
    {
        if (argument.StartsWith(ProtocolRegistrar.Protocol + ":", StringComparison.OrdinalIgnoreCase))
        {
            return ParseProtocol(argument);
        }

        if (File.Exists(argument))
        {
            return PrintJob.FromJson(File.ReadAllText(argument));
        }

        throw new InvalidOperationException("Give a .ptjob file or a partdb-bpac: URL.");
    }

    public static PrintJob ParseProtocol(string uri)
    {
        var payload = uri[(ProtocolRegistrar.Protocol.Length + 1)..];
        if (payload.StartsWith("print,", StringComparison.OrdinalIgnoreCase))
        {
            payload = payload[6..];
        }
        else if (payload.StartsWith("print/", StringComparison.OrdinalIgnoreCase))
        {
            payload = payload[6..];
        }

        payload = Uri.UnescapeDataString(payload).Trim();
        if (payload.Length == 0)
        {
            throw new InvalidOperationException("The partdb-bpac URL had no print job.");
        }

        return PrintJob.FromJson(Encoding.UTF8.GetString(Base64UrlDecode(payload)));
    }

    public static void Print(PrintJob job)
    {
        if (BpacPrinter.IsAvailable() && BpacPrinter.FindTemplate() is not null)
        {
            if (BpacPrinter.TryPrint(job, out var bpacError))
            {
                return;
            }

            AppLog.Write("Falling back to raster print: " + bpacError);
        }

        BrotherRasterPrinter.Print(job);
    }

    public static PrintJob SampleJob() => new()
    {
        WidthMm = 30,
        HeightMm = 18,
        Copies = 1,
        Labels =
        [
            new PrintLabel
            {
                Id = "0",
                Name = "Part-DB P-touch",
                Description = "30 x 18 mm test",
                QrUrl = "https://parts.4qt.org/",
            },
        ],
    };

    private static byte[] Base64UrlDecode(string value)
    {
        var padded = value.Replace('-', '+').Replace('_', '/');
        switch (padded.Length % 4)
        {
            case 2:
                padded += "==";
                break;
            case 3:
                padded += "=";
                break;
        }

        return Convert.FromBase64String(padded);
    }
}
