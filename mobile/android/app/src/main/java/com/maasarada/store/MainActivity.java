package com.maasarada.store;

import android.os.Bundle;
import com.getcapacitor.BridgeActivity;

public class MainActivity extends BridgeActivity {

    @Override
    public void onCreate(Bundle savedInstanceState) {
        // Local plugins register before the bridge starts.
        registerPlugin(MsShellPlugin.class);
        super.onCreate(savedInstanceState);
    }
}
