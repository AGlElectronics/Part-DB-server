using System.Runtime.InteropServices;

namespace PartDb.BpacBridge;

internal static class RawPrinter
{
    public static void Send(string printerName, byte[] data, string documentName)
    {
        if (string.IsNullOrWhiteSpace(printerName))
        {
            throw new InvalidOperationException("No Brother P-touch printer was selected.");
        }

        if (!OpenPrinter(printerName.Normalize(), out var printer, IntPtr.Zero))
        {
            throw new InvalidOperationException($"Could not open printer \"{printerName}\".");
        }

        try
        {
            var di = new DocInfo1
            {
                pDocName = documentName,
                pOutputFile = null,
                pDataType = "RAW",
            };

            if (StartDocPrinter(printer, 1, ref di) == 0)
            {
                throw new InvalidOperationException($"StartDocPrinter failed for \"{printerName}\".");
            }

            try
            {
                if (!StartPagePrinter(printer))
                {
                    throw new InvalidOperationException("StartPagePrinter failed.");
                }

                try
                {
                    var unmanaged = Marshal.AllocCoTaskMem(data.Length);
                    try
                    {
                        Marshal.Copy(data, 0, unmanaged, data.Length);
                        if (!WritePrinter(printer, unmanaged, data.Length, out var written) || written != data.Length)
                        {
                            throw new InvalidOperationException("WritePrinter failed. Turn Editor Lite off on the PT-P700 and retry.");
                        }
                    }
                    finally
                    {
                        Marshal.FreeCoTaskMem(unmanaged);
                    }
                }
                finally
                {
                    EndPagePrinter(printer);
                }
            }
            finally
            {
                EndDocPrinter(printer);
            }
        }
        finally
        {
            ClosePrinter(printer);
        }
    }

    [StructLayout(LayoutKind.Sequential, CharSet = CharSet.Unicode)]
    private struct DocInfo1
    {
        [MarshalAs(UnmanagedType.LPWStr)] public string pDocName;
        [MarshalAs(UnmanagedType.LPWStr)] public string? pOutputFile;
        [MarshalAs(UnmanagedType.LPWStr)] public string pDataType;
    }

    [DllImport("winspool.drv", EntryPoint = "OpenPrinterW", SetLastError = true, CharSet = CharSet.Unicode)]
    private static extern bool OpenPrinter(string pPrinterName, out IntPtr phPrinter, IntPtr pDefault);

    [DllImport("winspool.drv", SetLastError = true)]
    private static extern bool ClosePrinter(IntPtr hPrinter);

    [DllImport("winspool.drv", EntryPoint = "StartDocPrinterW", SetLastError = true, CharSet = CharSet.Unicode)]
    private static extern int StartDocPrinter(IntPtr hPrinter, int level, ref DocInfo1 di);

    [DllImport("winspool.drv", SetLastError = true)]
    private static extern bool EndDocPrinter(IntPtr hPrinter);

    [DllImport("winspool.drv", SetLastError = true)]
    private static extern bool StartPagePrinter(IntPtr hPrinter);

    [DllImport("winspool.drv", SetLastError = true)]
    private static extern bool EndPagePrinter(IntPtr hPrinter);

    [DllImport("winspool.drv", SetLastError = true)]
    private static extern bool WritePrinter(IntPtr hPrinter, IntPtr pBytes, int dwCount, out int dwWritten);
}
