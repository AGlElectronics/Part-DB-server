namespace PartDb.BpacBridge;

internal static class Program
{
    [STAThread]
    private static int Main(string[] args)
    {
        ApplicationConfiguration.Initialize();
        AppLog.Write("Start: " + string.Join(' ', args));

        try
        {
            if (args.Length == 0)
            {
                Application.Run(new MainForm());
                return 0;
            }

            var command = args[0];
            if (command.Equals("--install", StringComparison.OrdinalIgnoreCase))
            {
                var exe = ProtocolRegistrar.Install();
                MessageBox.Show(
                    "The Part-DB P-touch bridge is installed."
                    + Environment.NewLine + exe
                    + Environment.NewLine + Environment.NewLine
                    + "Windows will open print jobs from the browser.",
                    "Part-DB P-touch bridge",
                    MessageBoxButtons.OK,
                    MessageBoxIcon.Information);
                return 0;
            }

            if (command.Equals("--uninstall", StringComparison.OrdinalIgnoreCase))
            {
                ProtocolRegistrar.Uninstall();
                return 0;
            }

            if (command.Equals("--print", StringComparison.OrdinalIgnoreCase))
            {
                if (args.Length < 2)
                {
                    throw new InvalidOperationException("Usage: PartDbBpacBridge.exe --print <job.ptjob>");
                }

                PrintDispatcher.Print(PrintJob.FromJson(File.ReadAllText(args[1])));
                return 0;
            }

            if (command.Equals("--test", StringComparison.OrdinalIgnoreCase))
            {
                PrintDispatcher.Print(PrintDispatcher.SampleJob());
                return 0;
            }

            PrintDispatcher.Print(PrintDispatcher.ParseArgument(command));
            return 0;
        }
        catch (Exception ex)
        {
            AppLog.Write("Fatal: " + ex);
            MessageBox.Show(
                ex.Message + Environment.NewLine + Environment.NewLine
                + "Turn Editor Lite off on the PT-P700 if the printer shows up as a USB drive.",
                "Part-DB P-touch bridge",
                MessageBoxButtons.OK,
                MessageBoxIcon.Error);
            return 1;
        }
    }
}
