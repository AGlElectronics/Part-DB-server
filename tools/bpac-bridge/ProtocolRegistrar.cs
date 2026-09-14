using Microsoft.Win32;

namespace PartDb.BpacBridge;

internal static class ProtocolRegistrar
{
    public const string Protocol = "partdb-bpac";
    public const string JobExtension = ".ptjob";
    private const string JobProgId = "PartDb.BpacJob";

    public static string ExePath => Environment.ProcessPath
        ?? throw new InvalidOperationException("Could not resolve the bridge executable path.");

    public static string InstallDirectory => Path.Combine(
        Environment.GetFolderPath(Environment.SpecialFolder.LocalApplicationData),
        "Part-DB",
        "BpacBridge");

    public static string InstalledExePath => Path.Combine(InstallDirectory, "PartDbBpacBridge.exe");

    public static bool IsInstalled()
    {
        using var key = Registry.CurrentUser.OpenSubKey($@"Software\Classes\{Protocol}\shell\open\command");
        var command = key?.GetValue(null) as string;
        return !string.IsNullOrWhiteSpace(command)
            && command.Contains(InstalledExePath, StringComparison.OrdinalIgnoreCase)
            && File.Exists(InstalledExePath);
    }

    public static string Install()
    {
        var sourceDir = Path.GetDirectoryName(ExePath)
            ?? throw new InvalidOperationException("Could not resolve the bridge directory.");
        Directory.CreateDirectory(InstallDirectory);
        if (!string.Equals(Path.GetFullPath(sourceDir), Path.GetFullPath(InstallDirectory), StringComparison.OrdinalIgnoreCase))
        {
            foreach (var file in Directory.GetFiles(sourceDir))
            {
                var name = Path.GetFileName(file);
                if (name.EndsWith(".pdb", StringComparison.OrdinalIgnoreCase))
                {
                    continue;
                }

                File.Copy(file, Path.Combine(InstallDirectory, name), overwrite: true);
            }
        }

        var exe = InstalledExePath;
        using (var key = Registry.CurrentUser.CreateSubKey($@"Software\Classes\{Protocol}"))
        {
            key.SetValue(null, "URL:Part-DB P-touch Bridge");
            key.SetValue("URL Protocol", "");
            using var icon = key.CreateSubKey("DefaultIcon");
            icon.SetValue(null, $"\"{exe}\",0");
            using var command = key.CreateSubKey(@"shell\open\command");
            command.SetValue(null, $"\"{exe}\" \"%1\"");
        }

        using (var key = Registry.CurrentUser.CreateSubKey($@"Software\Classes\{JobExtension}"))
        {
            key.SetValue(null, JobProgId);
        }

        using (var key = Registry.CurrentUser.CreateSubKey($@"Software\Classes\{JobProgId}"))
        {
            key.SetValue(null, "Part-DB P-touch print job");
            using var icon = key.CreateSubKey("DefaultIcon");
            icon.SetValue(null, $"\"{exe}\",0");
            using var command = key.CreateSubKey(@"shell\open\command");
            command.SetValue(null, $"\"{exe}\" \"%1\"");
        }

        return exe;
    }

    public static void Uninstall()
    {
        Registry.CurrentUser.DeleteSubKeyTree($@"Software\Classes\{Protocol}", false);
        Registry.CurrentUser.DeleteSubKeyTree($@"Software\Classes\{JobExtension}", false);
        Registry.CurrentUser.DeleteSubKeyTree($@"Software\Classes\{JobProgId}", false);
    }
}
