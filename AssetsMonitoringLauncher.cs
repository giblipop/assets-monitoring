using System;
using System.Diagnostics;
using System.IO;
using System.Net.Sockets;
using System.Threading;
using System.Windows.Forms;

internal static class AssetsMonitoringLauncher
{
    private const string XamppDirectory = @"C:\xampp";
    private const string AppUrl = "http://localhost/assets-monitoring/page/login.html";

    [STAThread]
    private static void Main()
    {
        try
        {
            if (!IsPortOpen(80))
            {
                string starter = Path.Combine(XamppDirectory, "xampp_start.exe");
                if (!File.Exists(starter))
                    throw new FileNotFoundException("XAMPP could not be found at " + XamppDirectory + ".", starter);

                Process.Start(new ProcessStartInfo
                {
                    FileName = starter,
                    WorkingDirectory = XamppDirectory,
                    UseShellExecute = true,
                    WindowStyle = ProcessWindowStyle.Minimized
                });
            }

            if (!WaitForPort(80, 30000))
                throw new InvalidOperationException("Apache did not start. Open the XAMPP Control Panel and start Apache.");

            if (!WaitForPort(3306, 15000))
                throw new InvalidOperationException("MySQL did not start. Open the XAMPP Control Panel and start MySQL.");

            Process.Start(new ProcessStartInfo { FileName = AppUrl, UseShellExecute = true });
        }
        catch (Exception error)
        {
            MessageBox.Show(error.Message, "Assets Monitoring could not start", MessageBoxButtons.OK, MessageBoxIcon.Error);
        }
    }

    private static bool IsPortOpen(int port)
    {
        try
        {
            using (TcpClient client = new TcpClient())
            {
                IAsyncResult result = client.BeginConnect("127.0.0.1", port, null, null);
                using (result.AsyncWaitHandle)
                {
                    if (!result.AsyncWaitHandle.WaitOne(500)) return false;
                }
                client.EndConnect(result);
                return true;
            }
        }
        catch { return false; }
    }

    private static bool WaitForPort(int port, int timeoutMilliseconds)
    {
        Stopwatch timer = Stopwatch.StartNew();
        while (timer.ElapsedMilliseconds < timeoutMilliseconds)
        {
            if (IsPortOpen(port)) return true;
            Thread.Sleep(500);
        }
        return IsPortOpen(port);
    }
}
