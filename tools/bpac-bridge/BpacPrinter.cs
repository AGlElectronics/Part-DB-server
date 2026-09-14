namespace PartDb.BpacBridge;

internal static class BpacPrinter
{
    public static bool IsAvailable()
    {
        try
        {
            return Type.GetTypeFromProgID("bpac.Document") is not null;
        }
        catch
        {
            return false;
        }
    }

    public static string? FindTemplate()
    {
        var exeDir = AppContext.BaseDirectory;
        foreach (var name in new[] { "stacked.lbx", "label.lbx", "PartDB.lbx" })
        {
            var path = Path.Combine(exeDir, "templates", name);
            if (File.Exists(path))
            {
                return path;
            }
        }

        return null;
    }

    public static bool TryPrint(PrintJob job, out string? error)
    {
        error = null;
        var type = Type.GetTypeFromProgID("bpac.Document");
        var template = FindTemplate();
        if (type is null || template is null)
        {
            error = type is null
                ? "Brother b-PAC is not installed."
                : "No .lbx template was found next to the bridge.";
            return false;
        }

        try
        {
            dynamic doc = Activator.CreateInstance(type)
                ?? throw new InvalidOperationException("Could not create bpac.Document.");

            if (doc.Open(template) is false)
            {
                error = $"b-PAC could not open {template}.";
                return false;
            }

            try
            {
                if (!string.IsNullOrWhiteSpace(job.Printer))
                {
                    doc.SetPrinter(job.Printer, true);
                }

                try
                {
                    doc.Length = (float)Math.Max(job.WidthMm, BrotherRasterPrinter.MinLengthMm);
                }
                catch (Exception ex)
                {
                    AppLog.Write("b-PAC Length set failed: " + ex.Message);
                }

                foreach (var label in job.Labels)
                {
                    TrySetObject(doc, "name", label.Name);
                    TrySetObject(doc, "objName", label.Name);
                    TrySetObject(doc, "description", label.Description);
                    TrySetObject(doc, "objDescription", label.Description);
                    TrySetObject(doc, "qr_url", label.QrUrl);
                    TrySetObject(doc, "objQr", label.QrUrl);

                    doc.StartPrint("", 0);
                    doc.PrintOut(job.Copies, 0);
                    doc.EndPrint();
                }
            }
            finally
            {
                doc.Close();
            }

            AppLog.Write("b-PAC print succeeded.");
            return true;
        }
        catch (Exception ex)
        {
            error = ex.Message;
            AppLog.Write("b-PAC print failed: " + ex);
            return false;
        }
    }

    private static void TrySetObject(dynamic doc, string name, string value)
    {
        try
        {
            var obj = doc.GetObject(name);
            if (obj is not null)
            {
                obj.Text = value ?? "";
            }
        }
        catch
        {
            // Template may not contain this object.
        }
    }
}
