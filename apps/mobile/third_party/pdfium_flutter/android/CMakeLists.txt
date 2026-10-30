# Upstream pdfium_flutter0.1.9 (MIT), mobile artifact verification patch.
cmake_minimum_required(VERSION 3.18.1)
set(PROJECT_NAME "pdfium_flutter")
project(${PROJECT_NAME} LANGUAGES CXX)
cmake_policy(VERSION 3.14...3.25)
find_package(Python3 REQUIRED COMPONENTS Interpreter)
set(PDFIUM_OUTPUT "${CMAKE_SOURCE_DIR}/.lib/latest/${ANDROID_ABI}")
execute_process(
  COMMAND "${Python3_EXECUTABLE}" "${CMAKE_SOURCE_DIR}/fetch_pdfium.py"
    "--abi=${ANDROID_ABI}" "--cache=${CMAKE_BINARY_DIR}/pdfium-pinned" "--output=${PDFIUM_OUTPUT}"
  RESULT_VARIABLE PDFIUM_VERIFICATION
)
if(NOT PDFIUM_VERIFICATION EQUAL 0)
  message(FATAL_ERROR "Verified PDFium artifact is required for ${ANDROID_ABI}")
endif()
set(pdfium_flutter_bundled_libraries "${PDFIUM_OUTPUT}/libpdfium.so" PARENT_SCOPE)
