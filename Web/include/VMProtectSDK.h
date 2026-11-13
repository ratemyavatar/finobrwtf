#pragma once
#define VMProtectBegin(x)
#define VMProtectBeginVirtualization(x)
#define VMProtectBeginMutation(x)
#define VMProtectBeginUltra(x)
#define VMProtectEnd()
#define VMProtectIsDebuggerPresent(x) false
#define VMProtectIsVirtualMachinePresent() false
#define VMProtectIsValidImageCRC() true
#define VMProtectDecryptStringA(x) (x)
#define VMProtectFreeString(x)
