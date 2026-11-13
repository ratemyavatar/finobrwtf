#pragma once
#include <stdint.h>
typedef void* jobject;
typedef void* jclass;
typedef void* jstring;
typedef void* jmethodID;
typedef void* jfieldID;
typedef int32_t jint;
typedef int64_t jlong;
typedef float jfloat;
typedef double jdouble;
typedef uint8_t jboolean;
typedef struct JNIEnv_ JNIEnv;
typedef struct JavaVM_ JavaVM;
#define JNIEXPORT
#define JNICALL
