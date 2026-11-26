package com.maasarada.store;

import android.content.ActivityNotFoundException;
import android.content.Intent;
import android.content.pm.InstallSourceInfo;
import android.content.pm.PackageInfo;
import android.content.pm.PackageManager;
import android.net.Uri;
import android.os.Build;
import com.getcapacitor.JSObject;
import com.getcapacitor.Plugin;
import com.getcapacitor.PluginCall;
import com.getcapacitor.PluginMethod;
import com.getcapacitor.annotation.CapacitorPlugin;
import java.util.Locale;

/**
 * App-specific glue for the storefront shell (docs/mobile-app.md, phase 4).
 *
 *  - upi:, tel:, mailto:, sms:, whatsapp: links leave the WebView. UPI opens a chooser so the
 *    customer picks GPay / PhonePe / Paytm; with no app for the link, JS gets a "noApp" event.
 *  - info() tells the web side who installed the app (Play or a sideloaded APK), so it picks
 *    Play In-App Updates or the /app/version check. The app never installs APKs itself.
 *
 * Off-site https links (wa.me, maps) already open outside the WebView through the bridge.
 */
@CapacitorPlugin(name = "MsShell")
public class MsShellPlugin extends Plugin {

    public static final String PLAY_STORE = "com.android.vending";

    @Override
    public Boolean shouldOverrideLoad(Uri url) {
        String scheme = url.getScheme();
        if (scheme == null) {
            return null;
        }
        switch (scheme.toLowerCase(Locale.ROOT)) {
            case "upi":
                open(url, true);
                return true;
            case "tel":
            case "mailto":
            case "sms":
            case "whatsapp":
                open(url, false);
                return true;
            case "intent":
            case "file":
            case "content":
            case "javascript":
                return true; // never followed from a web page
            default:
                return null; // the bridge decides (same host stays, other hosts open outside)
        }
    }

    private void open(Uri url, boolean chooser) {
        Intent intent = new Intent(Intent.ACTION_VIEW, url);
        intent.addFlags(Intent.FLAG_ACTIVITY_NEW_TASK);
        PackageManager pm = getContext().getPackageManager();
        if (intent.resolveActivity(pm) == null) {
            noApp(url);
            return;
        }
        try {
            getContext().startActivity(chooser ? Intent.createChooser(intent, "Pay with").addFlags(Intent.FLAG_ACTIVITY_NEW_TASK) : intent);
        } catch (ActivityNotFoundException e) {
            noApp(url);
        }
    }

    private void noApp(Uri url) {
        JSObject data = new JSObject();
        data.put("scheme", url.getScheme());
        notifyListeners("noApp", data);
    }

    @PluginMethod
    public void info(PluginCall call) {
        JSObject ret = new JSObject();
        String installer = null;
        try {
            String pkg = getContext().getPackageName();
            PackageManager pm = getContext().getPackageManager();
            if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.R) {
                InstallSourceInfo src = pm.getInstallSourceInfo(pkg);
                installer = src.getInstallingPackageName();
            } else {
                installer = pm.getInstallerPackageName(pkg);
            }
            PackageInfo info = pm.getPackageInfo(pkg, 0);
            ret.put("versionName", info.versionName);
            ret.put("versionCode", Build.VERSION.SDK_INT >= Build.VERSION_CODES.P ? info.getLongVersionCode() : info.versionCode);
        } catch (Exception e) {
            // leave the fields out; the web side treats it as a sideloaded install
        }
        ret.put("installer", installer);
        ret.put("fromPlay", PLAY_STORE.equals(installer));
        call.resolve(ret);
    }
}
