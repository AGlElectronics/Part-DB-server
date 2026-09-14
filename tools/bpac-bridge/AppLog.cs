namespace PartDb.BpacBridge;

internal static class AppLog
{
    public static string LogDirectory => Path.Combine(
        Environment.GetFolderPath(Environment.SpecialFolder.LocalApplicationData),
        "Part-DB",
        "bpac-bridge");

    public static string LogFile => Path.Combine(LogDirectory, "bridge.log");

    public static void Write(string message)
    {
        try
        {
            Directory.CreateDirectory(LogDirectory);
            File.AppendAllText(LogFile, $"{DateTime.Now:yyyy-MM-dd HH:mm:ss} {message}{Environment.NewLine}");
        }
        catch
        {
            // Logging must never break printing.
        }
    }
}
