namespace PartDb.BpacBridge;

internal sealed class MainForm : Form
{
    private readonly TextBox _log = new();
    private readonly Label _status = new();

    public MainForm()
    {
        Text = "Part-DB P-touch bridge";
        Width = 640;
        Height = 420;
        MinimumSize = new Size(520, 320);
        StartPosition = FormStartPosition.CenterScreen;
        Font = new Font("Segoe UI", 9.75f);

        var install = new Button { Text = "Install", AutoSize = true };
        var uninstall = new Button { Text = "Uninstall", AutoSize = true };
        var test = new Button { Text = "Print 30×18 test", AutoSize = true };
        var refresh = new Button { Text = "Refresh", AutoSize = true };

        install.Click += (_, _) => Run("Install", () => ProtocolRegistrar.Install());
        uninstall.Click += (_, _) => Run("Uninstall", ProtocolRegistrar.Uninstall);
        test.Click += (_, _) => Run("Test print", () => PrintDispatcher.Print(PrintDispatcher.SampleJob()));
        refresh.Click += (_, _) => RefreshStatus();

        var buttons = new FlowLayoutPanel
        {
            Dock = DockStyle.Top,
            AutoSize = true,
            Padding = new Padding(8),
            WrapContents = true,
        };
        buttons.Controls.AddRange([install, uninstall, test, refresh]);

        _status.Dock = DockStyle.Top;
        _status.AutoSize = true;
        _status.Padding = new Padding(12, 4, 12, 8);

        _log.Dock = DockStyle.Fill;
        _log.Multiline = true;
        _log.ReadOnly = true;
        _log.ScrollBars = ScrollBars.Vertical;
        _log.Font = new Font("Consolas", 9f);

        Controls.Add(_log);
        Controls.Add(_status);
        Controls.Add(buttons);

        Load += (_, _) => RefreshStatus();
    }

    private void RefreshStatus()
    {
        var printers = BrotherRasterPrinter.ListPrinters();
        var selected = BrotherRasterPrinter.FindPrinter(null) ?? "(none)";
        _status.Text =
            $"Protocol: {(ProtocolRegistrar.IsInstalled() ? "installed" : "not installed")}"
            + $"{Environment.NewLine}b-PAC: {(BpacPrinter.IsAvailable() ? "available" : "not installed (raster print is used)")}"
            + $"{Environment.NewLine}Template: {BpacPrinter.FindTemplate() ?? "(none — raster backend)"}"
            + $"{Environment.NewLine}Printer: {selected}"
            + $"{Environment.NewLine}Installed printers: {(printers.Count == 0 ? "(none)" : string.Join(", ", printers))}";

        Append($"Ready. Log file: {AppLog.LogFile}");
    }

    private void Run(string title, Action action)
    {
        try
        {
            action();
            Append($"{title} succeeded.");
            RefreshStatus();
        }
        catch (Exception ex)
        {
            Append($"{title} failed: {ex.Message}");
            MessageBox.Show(this, ex.Message, title, MessageBoxButtons.OK, MessageBoxIcon.Error);
        }
    }

    private void Append(string line)
    {
        _log.AppendText(line + Environment.NewLine);
        AppLog.Write(line);
    }
}
